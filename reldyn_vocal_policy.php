<?php

/**
 * RelDyn vocal style bridge (LOCAL: branch reldyn-scenes of Ken's checkout, never pushed or proposed upstream).
 *
 * Ken's ruling of 2026-10-01: how much an NPC talks in bed is a matter of who the NPC is, not one setting for everyone.
 * RelDyn (ext/relationship_dynamics) publishes one vocal style per NPC to core_npc_master.plugin_extended_data -> 'reldyn' ->
 * 'vocal' on every request of that NPC:
 *   {"v":1,"enabled":true,"style":"vocal|whispered|minimal|seeking|sharp|open","silence_chance":0..0.9,"pace":0.8..1.25,
 *    "felt":"<one line about how the NPC sounds in bed>","gamets":n}
 * silence_chance is the chance that a given scene beat gets no spoken line at all (the game's own moans and breaths still
 * play: those are OStim's, not the model's). It is never 0 and never 1: no NPC is certain to speak and none is certain to
 * stay silent. pace multiplies the scene voice speed a little (a whisperer a touch slower).
 *
 * Sharmat's own per-style silence_chance (an optional field of a speak style, 0..1) combines with it: two independent
 * reasons to be quiet, so the chance of speaking is the product of the chances of speaking.
 *
 * Pure of CHIM except the database handle ($GLOBALS['db']) and the optional settings helper, so tests/reldyn_vocal_test.php
 * loads it with a fixture. INERT without RelDyn: with no ext/relationship_dynamics beside this extension, with RelDyn switched
 * off, with RelDyn's vocal style switched off (it publishes enabled:false), with NSFW_RELDYN_VOCAL set false, or with no valid
 * style published for the NPC, every reader returns null and every NPC talks exactly as before (a speak style's own
 * silence_chance, if one is set, still applies).
 */

/** The ceiling of a combined silence chance: even the quietest NPC speaks now and then. */
function aiagentNsfwVocalSilenceCeiling()
{
    return 0.92;
}

function &aiagentNsfwRelDynVocalCache()
{
    static $cache = ['decisions' => []];
    return $cache;
}

function aiagentNsfwRelDynVocalReset()
{
    $cache = &aiagentNsfwRelDynVocalCache();
    $cache = ['decisions' => []];
}

/**
 * One published vocal value (a JSON string or the decoded array) as the style Sharmat uses, or null when it is not one: not
 * version 1, switched off, or malformed. Pure.
 *
 * @return array|null ['style' => string, 'silence_chance' => float, 'pace' => float, 'felt' => string]
 */
function aiagentNsfwRelDynVocalFromRow($raw)
{
    $v = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($v) || (int)($v['v'] ?? 0) !== 1 || empty($v['enabled'])) { return null; }
    $style = strtolower(trim((string)($v['style'] ?? '')));
    if (!in_array($style, ['vocal', 'whispered', 'minimal', 'seeking', 'sharp', 'open'], true)) { return null; }
    if (!isset($v['silence_chance']) || !is_numeric($v['silence_chance'])) { return null; }
    $silence = (float)$v['silence_chance'];
    if ($silence < 0.0 || $silence > 1.0) { return null; }
    $pace = (isset($v['pace']) && is_numeric($v['pace']) && (float)$v['pace'] > 0) ? (float)$v['pace'] : 1.0;
    return [
        'style'          => $style,
        'silence_chance' => min($silence, aiagentNsfwVocalSilenceCeiling()),
        'pace'           => max(0.8, min(1.25, $pace)),
        'felt'           => is_string($v['felt'] ?? null) ? trim($v['felt']) : '',
    ];
}

/**
 * RelDyn's current vocal style for this NPC, or null when RelDyn does not publish one (see the file comment). Read from the
 * database once per request per NPC. Presence is the consent bridge's: installed beside Sharmat and switched on.
 */
function aiagentNsfwRelDynVocal($npcName)
{
    $npcName = trim((string)$npcName);
    if ($npcName === '') { return null; }
    $cache = &aiagentNsfwRelDynVocalCache();
    $key = strtolower($npcName);
    if (array_key_exists($key, $cache['decisions'])) { return $cache['decisions'][$key]; }
    $vocal = null;
    $on = function_exists('aiagentNsfwRelDynConsentPresent') && aiagentNsfwRelDynConsentPresent()
        && (!function_exists('_getNsfwSetting') || _getNsfwSetting('NSFW_RELDYN_VOCAL', true));
    if ($on && isset($GLOBALS['db']) && $GLOBALS['db']) {
        try {
            $row = $GLOBALS['db']->fetchOne(
                "SELECT plugin_extended_data -> 'reldyn' -> 'vocal' AS vocal FROM core_npc_master"
                . " WHERE lower(npc_name) = lower('" . $GLOBALS['db']->escape($npcName) . "') ORDER BY id LIMIT 1"
            );
            if ($row && isset($row['vocal'])) { $vocal = aiagentNsfwRelDynVocalFromRow($row['vocal']); }
        } catch (Throwable $e) {
            error_log("[AIAGENTNSFW] RelDyn vocal style read failed for {$npcName}: " . $e->getMessage() . " - no vocal style applies");
        }
    }
    $cache['decisions'][$key] = $vocal;
    return $vocal;
}

/** The NPC's pace multiplier on the scene voice speed (1.0 without RelDyn). */
function aiagentNsfwRelDynVocalPace($npcName)
{
    $v = aiagentNsfwRelDynVocal($npcName);
    return $v === null ? 1.0 : $v['pace'];
}

/** The line about how the NPC sounds in bed, for the model; '' without RelDyn. */
function aiagentNsfwRelDynVocalLine($npcName)
{
    $v = aiagentNsfwRelDynVocal($npcName);
    return $v === null ? '' : $v['felt'];
}

/**
 * The chance that a scene beat of this NPC gets no spoken line: RelDyn's (null without it) and the speak style's own (null when
 * the style sets none) as two independent reasons to be quiet. null when neither speaks (no silencing at all). Pure.
 */
function aiagentNsfwVocalSilenceChance($vocal, $styleChance)
{
    $r = (is_array($vocal) && isset($vocal['silence_chance']) && is_numeric($vocal['silence_chance'])) ? max(0.0, min(1.0, (float)$vocal['silence_chance'])) : null;
    $s = (is_numeric($styleChance) && $styleChance !== '') ? max(0.0, min(1.0, (float)$styleChance)) : null;
    if ($r === null && $s === null) { return null; }
    $speak = (1.0 - ($r ?? 0.0)) * (1.0 - ($s ?? 0.0));
    return round(min(aiagentNsfwVocalSilenceCeiling(), 1.0 - $speak), 4);
}

/**
 * Is this beat one that may go quiet? Only the beat-driven talk of an NPC who is in an accepted, explicit scene: a position
 * change or the OStim talk trigger. Never the decision turn (the accept or refuse beat), a refusal, an idle or intro beat, an
 * orgasm (climax speech has its own switch), a moan event (an involuntary sound), nor a slave or a paid service (their
 * speech is their role). Pure.
 *
 * @param string $event     the event name captured before the scene handlers rewrote it
 * @param array  $intimacy  the NPC's intimacy status
 */
function aiagentNsfwVocalSilenceApplies($event, array $intimacy, $isSlave = false, $isProstitute = false)
{
    if ($isSlave || $isProstitute) { return false; }
    $playerBeats = ['ext_nsfw_sexcene', 'chatnf_sl', 'chatnf_sl_nr'];
    $npcBeats = ['ext_nsfw_npc_scene', 'chatnf_npc_sl'];
    $phase = strtolower((string)($intimacy['scene_phase'] ?? ''));
    if (!empty($intimacy['refusal_expressed']) || !empty($intimacy['request_scene_stop']) || !empty($intimacy['scene_is_idle'])) { return false; }
    if (!in_array($phase, ['accepted', 'engaged'], true)) { return false; }
    if (!empty($intimacy['is_npc_scene'])) {
        return in_array($event, $npcBeats, true) && !empty($intimacy['sex_started']);
    }
    return in_array($event, $playerBeats, true)
        && (int)($intimacy['intensity_tier'] ?? 0) >= 3
        && !empty($intimacy['accepted_sex']);
}

/**
 * Decide one beat. $roll is a draw in [0,1); the beat is silent when it falls under the chance, except after $maxSilentStreak
 * silent beats in a row, when the NPC speaks (a quiet NPC is not mute). Pure.
 *
 * @return array ['silent' => bool, 'chance' => ?float, 'streak' => int (the streak after this beat), 'forced' => bool]
 */
function aiagentNsfwVocalSilenceDecide($chance, $roll, $streak = 0, $maxSilentStreak = 8)
{
    $streak = max(0, (int)$streak);
    if ($chance === null || $chance <= 0.0) { return ['silent' => false, 'chance' => $chance, 'streak' => 0, 'forced' => false]; }
    $max = max(1, (int)$maxSilentStreak);
    if ($streak >= $max) { return ['silent' => false, 'chance' => $chance, 'streak' => 0, 'forced' => true]; }
    $silent = (float)$roll < (float)$chance;
    return ['silent' => $silent, 'chance' => $chance, 'streak' => $silent ? $streak + 1 : 0, 'forced' => false];
}

/**
 * The whole silence gate for one beat of one NPC: applicable? (aiagentNsfwVocalSilenceApplies), the chance (RelDyn's vocal
 * style and the speak style's own), a draw, the streak kept in $streakFile (sys_get_temp_dir()-style file, one per NPC; null
 * keeps none). Returns the decision array of aiagentNsfwVocalSilenceDecide(), or null when this beat is not one that may go
 * quiet or no reason to be quiet applies; the caller silences the turn (AIAGENTNSFW_FORCE_STOP) when 'silent' is true.
 *
 * @param float|null $roll  a draw in [0,1) for tests; null draws one
 */
function aiagentNsfwVocalSilenceGate($npcName, $event, array $intimacy, $isSlave = false, $isProstitute = false, $styleChance = null, $roll = null, $streakFile = null, $maxSilentStreak = 8)
{
    if (!aiagentNsfwVocalSilenceApplies($event, $intimacy, $isSlave, $isProstitute)) { return null; }
    $chance = aiagentNsfwVocalSilenceChance(aiagentNsfwRelDynVocal($npcName), $styleChance);
    if ($chance === null) { return null; }
    $streak = 0;
    if ($streakFile !== null && is_file($streakFile)) { $streak = (int)trim((string)@file_get_contents($streakFile)); }
    if ($roll === null) { $roll = mt_rand() / (mt_getrandmax() + 1); }
    $d = aiagentNsfwVocalSilenceDecide($chance, $roll, $streak, $maxSilentStreak);
    if ($streakFile !== null) { @file_put_contents($streakFile, (string)$d['streak'], LOCK_EX); }
    return $d;
}
