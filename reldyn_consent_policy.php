<?php

/**
 * RelDyn consent bridge (LOCAL: branch reldyn-consent of Ken's checkout, never pushed or proposed upstream).
 *
 * Ken's ruling of 2026-10-01: Sharmat handles the "during"; RelDyn (the relationship dynamics extension, ext/relationship_dynamics)
 * decides whether intimacy happens at all, and its decision supersedes Sharmat's consent. When RelDyn says no, Sharmat refuses.
 *
 * RelDyn publishes one decision per NPC to core_npc_master.plugin_extended_data -> 'reldyn' -> 'consent' on every request of that NPC:
 *   {"v":1,"enabled":true,"allow":bool,"stance":"willing|hesitant|appeasing|declines|closed","closed":bool,
 *    "reasons":["conflict","ick",...],"appeasing":bool,"willingness":0..1,"bar":0..1,"felt":"<one line of feeling>","gamets":n}
 * allow is true for willing, hesitant (yes, unsure) and appeasing (yes, though unwilling: an NPC who gives in); false for declines
 * and closed (an asexual or aromantic NPC, a friendzone, a walkaway). "felt" is the NPC's own name and no pronoun: it is meant to be
 * read out to the model as is.
 *
 * This file is pure of CHIM except the database handle ($GLOBALS['db']) and the optional settings helper, so the hook can be
 * regression-tested without bootstrapping the server (tests/reldyn_consent_test.php). It is INERT without RelDyn: with no
 * ext/relationship_dynamics beside this extension, with RelDyn switched off (its config row), with RelDyn's consent switched off
 * (it publishes enabled:false), with the setting NSFW_RELDYN_CONSENT set false, or with no valid decision published for the NPC,
 * every function here returns null / false and Sharmat's own consent runs exactly as before.
 */

/** Per-request caches of the readers below; aiagentNsfwRelDynConsentReset() clears them (tests, long-lived processes). */
function &aiagentNsfwRelDynConsentCache()
{
    static $cache = ['present' => null, 'decisions' => []];
    return $cache;
}

function aiagentNsfwRelDynConsentReset()
{
    $cache = &aiagentNsfwRelDynConsentCache();
    $cache = ['present' => null, 'decisions' => []];
}

/**
 * Is RelDyn installed beside Sharmat and switched on, and may it decide consent? Installed = its extension directory exists (core
 * loads every ext/ directory) or its class is already loaded; on = its own master switch (conf_opts relationship_dynamics_config).
 */
function aiagentNsfwRelDynConsentPresent()
{
    $cache = &aiagentNsfwRelDynConsentCache();
    if ($cache['present'] !== null) { return $cache['present']; }
    $present = false;
    if (!function_exists('_getNsfwSetting') || _getNsfwSetting('NSFW_RELDYN_CONSENT', true)) {
        $installed = class_exists('RelationshipDynamics', false)
            || is_file(dirname(__DIR__) . '/relationship_dynamics/manifest.json');
        if ($installed) {
            $present = true;
            if (isset($GLOBALS['db']) && $GLOBALS['db']) {
                try {
                    $row = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = 'relationship_dynamics_config'");
                    if ($row && !empty($row['value'])) {
                        $cfg = json_decode($row['value'], true);
                        if (is_array($cfg) && array_key_exists('enabled', $cfg) && !$cfg['enabled']) { $present = false; }
                    }
                } catch (Throwable $e) {
                    // an unreadable config row does not make RelDyn absent: its decision is still the better answer
                }
            }
        }
    }
    $cache['present'] = $present;
    return $present;
}

/**
 * One published consent value (a JSON string or the decoded array) as the decision Sharmat obeys, or null when it is not one: not
 * version 1, switched off (enabled false), or malformed (allow not a boolean, or contradicting its stance). Pure.
 *
 * @return array|null ['allow' => bool, 'stance' => string, 'closed' => bool, 'reasons' => string[], 'appeasing' => bool, 'felt' => string]
 */
function aiagentNsfwRelDynConsentFromRow($raw)
{
    $c = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($c) || (int)($c['v'] ?? 0) !== 1 || empty($c['enabled'])) { return null; }
    if (!array_key_exists('allow', $c) || !is_bool($c['allow'])) { return null; }
    $stance = strtolower(trim((string)($c['stance'] ?? '')));
    if (!in_array($stance, ['willing', 'hesitant', 'appeasing', 'declines', 'closed'], true)) { return null; }
    if ($c['allow'] !== in_array($stance, ['willing', 'hesitant', 'appeasing'], true)) { return null; }
    $reasons = [];
    foreach ((array)($c['reasons'] ?? []) as $r) {
        if (is_string($r) && $r !== '') { $reasons[] = $r; }
    }
    return [
        'allow'     => $c['allow'],
        'stance'    => $stance,
        'closed'    => $stance === 'closed',
        'reasons'   => $reasons,
        'appeasing' => $stance === 'appeasing',
        'felt'      => is_string($c['felt'] ?? null) ? trim($c['felt']) : '',
    ];
}

/**
 * RelDyn's current decision for this NPC, or null when RelDyn does not decide it (see the file comment): the caller then keeps
 * Sharmat's own consent. Read from the database once per request per NPC.
 */
function aiagentNsfwRelDynConsentDecision($npcName)
{
    $npcName = trim((string)$npcName);
    if ($npcName === '') { return null; }
    $cache = &aiagentNsfwRelDynConsentCache();
    $key = strtolower($npcName);
    if (array_key_exists($key, $cache['decisions'])) { return $cache['decisions'][$key]; }
    $decision = null;
    if (aiagentNsfwRelDynConsentPresent() && isset($GLOBALS['db']) && $GLOBALS['db']) {
        try {
            $row = $GLOBALS['db']->fetchOne(
                "SELECT plugin_extended_data -> 'reldyn' -> 'consent' AS consent FROM core_npc_master"
                . " WHERE lower(npc_name) = lower('" . $GLOBALS['db']->escape($npcName) . "') ORDER BY id LIMIT 1"
            );
            if ($row && isset($row['consent'])) { $decision = aiagentNsfwRelDynConsentFromRow($row['consent']); }
        } catch (Throwable $e) {
            error_log("[AIAGENTNSFW] RelDyn consent read failed for {$npcName}: " . $e->getMessage() . " - Sharmat's own consent applies");
        }
    }
    $cache['decisions'][$key] = $decision;
    return $decision;
}

/** True when RelDyn decides this NPC's consent and says no. */
function aiagentNsfwRelDynRefuses($npcName)
{
    $d = aiagentNsfwRelDynConsentDecision($npcName);
    return $d !== null && empty($d['allow']);
}

/**
 * Sharmat's own world rules that RelDyn does not read and that only ever restrict: an NPC married to someone else needs the affair
 * floor (NSFW_AFFAIR_MIN_AFFINITY, default Fond 56) before she or he may be intimate with the player. A RelDyn yes replaces the
 * relationship-type grid and the affinity floors, not this. Returns the floor when it applies, else null.
 */
function aiagentNsfwRelDynAffairFloor($npcName)
{
    try {
        if (!class_exists('NsfwNpcData')) { return null; }
        $ext = NsfwNpcData::get($npcName);
        $spouse = trim((string)($ext['spouse_names'] ?? ''));
        $status = strtolower(trim((string)($ext['spousal_status'] ?? '')));
        $player = trim((string)($GLOBALS['PLAYER_NAME'] ?? ''));
        $married = ($spouse !== '') || in_array($status, ['married', 'betrothed'], true);
        $toPlayer = ($spouse !== '' && $player !== '' && stripos($spouse, $player) !== false);
        if (!$married || $toPlayer) { return null; }
        return function_exists('aiagentNsfwArousalNum') ? (int)aiagentNsfwArousalNum('NSFW_AFFAIR_MIN_AFFINITY', 56) : 56;
    } catch (Throwable $t) {
        return null;
    }
}

/**
 * The answer for aiagentNsfwRelTypeSexEligible when RelDyn decides: null (it does not: carry on with Sharmat's own gate), false
 * (RelDyn says no, or says yes and Sharmat's own orientation / affair rules still say no), or true (RelDyn says yes).
 */
function aiagentNsfwRelDynConsentEligibility($npcName)
{
    $d = aiagentNsfwRelDynConsentDecision($npcName);
    if ($d === null) { return null; }
    if (empty($d['allow'])) {
        error_log("[AIAGENTNSFW] RELDYN CONSENT {$npcName}: {$d['stance']} (" . implode(',', $d['reasons']) . ") => NOT eligible (RelDyn supersedes; should refuse)");
        return false;
    }
    if (function_exists('aiagentNsfwOrientationAllowsPlayer') && !aiagentNsfwOrientationAllowsPlayer($npcName)) {
        error_log("[AIAGENTNSFW] RELDYN CONSENT {$npcName}: {$d['stance']} but orientation does not allow the player => NOT eligible");
        return false;
    }
    $floor = aiagentNsfwRelDynAffairFloor($npcName);
    if ($floor !== null && function_exists('getNpcAffinity') && (int)getNpcAffinity($npcName) < $floor) {
        error_log("[AIAGENTNSFW] RELDYN CONSENT {$npcName}: {$d['stance']} but married to another and below the affair floor {$floor} => NOT eligible");
        return false;
    }
    error_log("[AIAGENTNSFW] RELDYN CONSENT {$npcName}: {$d['stance']} => ELIGIBLE (RelDyn supersedes the rel-type grid)");
    return true;
}
