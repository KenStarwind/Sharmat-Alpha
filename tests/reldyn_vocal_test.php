<?php

/**
 * Regression harness for the LOCAL RelDyn vocal style bridge (branch reldyn-scenes).
 *
 * Loads the real reldyn_consent_policy.php (its presence check) and the real reldyn_vocal_policy.php against a fixture: a fake
 * database handle holding the config row and the plugin_extended_data RelDyn publishes. The published fixtures are payloads
 * RelDynVocal::payload() produces for the test beds (Aela, Ashe, Muiri, Lynly Star-Sung) at a mid bond, captured from the RelDyn
 * side (unittests/tests/RelDynVocalTestBedsPostgresTest.php).
 *
 * Run: php tests/reldyn_vocal_test.php
 */

$failures = [];
$assertions = 0;
function checkRelDynVocal($condition, $message)
{
    global $failures, $assertions;
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
}
function sameRelDynVocal($expected, $actual, $message)
{
    checkRelDynVocal($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

// ---------------------------------------------------------------- the fixture world

ini_set('error_log', sys_get_temp_dir() . '/reldyn_vocal_test.log');
$GLOBALS['fx'] = ['conf' => [], 'npc' => [], 'queries' => [], 'settings' => []];

class FixtureVocalDb
{
    public function escape($s) { return str_replace("'", "''", (string)$s); }

    public function fetchOne($sql)
    {
        $GLOBALS['fx']['queries'][] = $sql;
        if (preg_match("/FROM conf_opts WHERE id = '([^']+)'/", $sql, $m)) {
            return isset($GLOBALS['fx']['conf'][$m[1]]) ? ['value' => $GLOBALS['fx']['conf'][$m[1]]] : [];
        }
        if (strpos($sql, "plugin_extended_data -> 'reldyn' -> 'vocal'") !== false) {
            if (!preg_match("/lower\\('((?:[^']|'')*)'\\)/", $sql, $m)) { return []; }
            $name = strtolower(str_replace("''", "'", $m[1]));
            if (!array_key_exists($name, $GLOBALS['fx']['npc'])) { return []; }
            return ['vocal' => $GLOBALS['fx']['npc'][$name]];
        }
        return [];
    }
}
$GLOBALS['db'] = new FixtureVocalDb();
function _getNsfwSetting($key, $default = null) { return array_key_exists($key, $GLOBALS['fx']['settings']) ? $GLOBALS['fx']['settings'][$key] : $default; }

require_once dirname(__DIR__) . '/reldyn_consent_policy.php';
require_once dirname(__DIR__) . '/reldyn_vocal_policy.php';

// ---------------------------------------------------------------- published fixtures (RelDynVocal::payload())

$published = [
    'Ashe'            => '{"v":1,"enabled":true,"style":"minimal","silence_chance":0.62,"pace":0.95,"felt":"Ashe says little in bed: breath, a sound, a few words at most, and what is said lands because it is rare. Commentary is not Ashe\'s way.","gamets":2072916666}',
    'Aela the Huntress' => '{"v":1,"enabled":true,"style":"minimal","silence_chance":0.4,"pace":0.95,"felt":"Aela the Huntress says little in bed: breath, a sound, a few words at most, and what is said lands because it is rare. Commentary is not Aela the Huntress\'s way.","gamets":2072916666}',
    'Muiri'           => '{"v":1,"enabled":true,"style":"sharp","silence_chance":0.37,"pace":1.03,"felt":"Muiri is sharp in bed: demanding and biting, hard to please, saying what is wanted as an order or turning a tender moment into a contest.","gamets":2072916666}',
    'Lynly Star-Sung' => '{"v":1,"enabled":true,"style":"vocal","silence_chance":0.18,"pace":1,"felt":"Lynly Star-Sung is open about it in bed: loud, plainspoken, happy to say what feels good and what is wanted, and to hear it back.","gamets":2072916666}',
    'Open'            => '{"v":1,"enabled":true,"style":"open","silence_chance":0.2,"pace":1,"felt":null,"gamets":2072916666}',
];

function vocalWorld(array $set)
{
    aiagentNsfwRelDynConsentReset();
    aiagentNsfwRelDynVocalReset();
    $fx = &$GLOBALS['fx'];
    $fx['queries'] = [];
    $fx['conf'] = [];
    $fx['npc'] = [];
    $fx['settings'] = [];
    foreach ($set as $k => $v) { $fx[$k] = $v; }
}
function vocalQueries()
{
    return array_filter($GLOBALS['fx']['queries'], function ($q) { return strpos($q, "'vocal'") !== false; });
}
function publishedAll($published)
{
    $npc = [];
    foreach ($published as $name => $json) { $npc[strtolower($name)] = $json; }
    return $npc;
}

// ---------------------------------------------------------------- 1. inert without RelDyn

vocalWorld(['npc' => publishedAll($published)]);
sameRelDynVocal(false, aiagentNsfwRelDynConsentPresent(), 'without RelDyn installed the bridge is not present');
sameRelDynVocal(null, aiagentNsfwRelDynVocal('Ashe'), 'without RelDyn there is no vocal style, whatever is stored');
sameRelDynVocal(1.0, aiagentNsfwRelDynVocalPace('Ashe'), 'without RelDyn the pace is untouched');
sameRelDynVocal('', aiagentNsfwRelDynVocalLine('Ashe'), 'without RelDyn there is no line for the model');
sameRelDynVocal([], vocalQueries(), 'without RelDyn the vocal row is never even read');
$inScene = ['scene_phase' => 'engaged', 'intensity_tier' => 3, 'accepted_sex' => true];
sameRelDynVocal(null, aiagentNsfwVocalSilenceGate('Ashe', 'ext_nsfw_sexcene', $inScene, false, false, null, 0.0), 'without RelDyn and no style silence: nothing is ever silenced');
$g = aiagentNsfwVocalSilenceGate('Ashe', 'ext_nsfw_sexcene', $inScene, false, false, 0.7, 0.5);
checkRelDynVocal($g !== null && $g['silent'] === true && abs($g['chance'] - 0.7) < 1e-9, 'a speak style\'s own silence_chance works without RelDyn: ' . json_encode($g));

// ---------------------------------------------------------------- 2. RelDyn installed: parse what it publishes

eval('class RelationshipDynamics {}');   // installed (eval so it is not hoisted above section 1)

vocalWorld(['npc' => publishedAll($published)]);
sameRelDynVocal(true, aiagentNsfwRelDynConsentPresent(), 'with RelDyn installed the bridge is present');
$a = aiagentNsfwRelDynVocal('Ashe');
checkRelDynVocal(is_array($a) && $a['style'] === 'minimal' && abs($a['silence_chance'] - 0.62) < 1e-9 && abs($a['pace'] - 0.95) < 1e-9, 'Ashe: ' . json_encode($a));
checkRelDynVocal(strpos($a['felt'], 'Ashe says little') === 0 && !preg_match('/\b(she|he|her|his|him)\b/i', $a['felt']), 'the line names the NPC and assumes no pronoun');
$l = aiagentNsfwRelDynVocal('Lynly Star-Sung');
checkRelDynVocal($l['style'] === 'vocal' && abs($l['silence_chance'] - 0.18) < 1e-9 && $l['pace'] === 1.0, 'Lynly: ' . json_encode($l));
sameRelDynVocal('', aiagentNsfwRelDynVocal('Open')['felt'], 'the open register carries no line');
sameRelDynVocal('', aiagentNsfwRelDynVocalLine('Open'), 'and none reaches the model');
sameRelDynVocal(1.03, aiagentNsfwRelDynVocalPace('Muiri'), 'a sharp NPC is a touch quicker');
sameRelDynVocal(null, aiagentNsfwRelDynVocal('Nobody'), 'no NPC row: no vocal style');
sameRelDynVocal(null, aiagentNsfwRelDynVocal(''), 'no name: no vocal style');
vocalWorld(['npc' => ['ashe' => null]]);
sameRelDynVocal(null, aiagentNsfwRelDynVocal('Ashe'), 'a row with no vocal key: none');
// the apostrophe in a name does not break the query
vocalWorld(['npc' => ["d'arcy" => $published['Ashe']]]);
checkRelDynVocal(aiagentNsfwRelDynVocal("D'Arcy") !== null, 'a quote in the NPC name is escaped');

// what is not a valid style is not obeyed
$base = json_decode($published['Ashe'], true);
foreach ([
    'version 2'          => array_replace($base, ['v' => 2]),
    'switched off'       => ['v' => 1, 'enabled' => false, 'gamets' => 1],
    'unknown style'      => array_replace($base, ['style' => 'screaming']),
    'no chance'          => array_diff_key($base, ['silence_chance' => 1]),
    'chance above one'   => array_replace($base, ['silence_chance' => 1.5]),
    'chance below zero'  => array_replace($base, ['silence_chance' => -0.1]),
    'chance not a number'=> array_replace($base, ['silence_chance' => 'high']),
    'not an object'      => 'minimal',
] as $label => $payload) {
    vocalWorld(['npc' => ['ashe' => is_string($payload) ? json_encode($payload) : json_encode($payload)]]);
    sameRelDynVocal(null, aiagentNsfwRelDynVocal('Ashe'), "{$label}: not a vocal style");
}
vocalWorld(['npc' => ['ashe' => 'not json at all']]);
sameRelDynVocal(null, aiagentNsfwRelDynVocal('Ashe'), 'garbage: none');
// bounds: a published chance of 1 is held under the ceiling, a missing pace is 1, a wild pace is bounded
vocalWorld(['npc' => ['ashe' => json_encode(array_replace($base, ['silence_chance' => 1.0, 'pace' => 9]))]]);
$b = aiagentNsfwRelDynVocal('Ashe');
sameRelDynVocal(aiagentNsfwVocalSilenceCeiling(), $b['silence_chance'], 'a chance of 1 is held under the ceiling: nobody is certain to stay silent');
sameRelDynVocal(1.25, $b['pace'], 'a wild pace is bounded');
vocalWorld(['npc' => ['ashe' => json_encode(array_diff_key($base, ['pace' => 1]))]]);
sameRelDynVocal(1.0, aiagentNsfwRelDynVocal('Ashe')['pace'], 'a missing pace is 1');

// RelDyn off in its own config row, or this bridge off here: no vocal style
vocalWorld(['npc' => publishedAll($published), 'conf' => ['relationship_dynamics_config' => '{"enabled":false}']]);
sameRelDynVocal(null, aiagentNsfwRelDynVocal('Ashe'), 'RelDyn switched off: no vocal style');
vocalWorld(['npc' => publishedAll($published), 'settings' => ['NSFW_RELDYN_VOCAL' => false]]);
sameRelDynVocal(null, aiagentNsfwRelDynVocal('Ashe'), 'NSFW_RELDYN_VOCAL off: no vocal style');
sameRelDynVocal([], vocalQueries(), 'and the row is not read');
vocalWorld(['npc' => publishedAll($published), 'settings' => ['NSFW_RELDYN_CONSENT' => false]]);
sameRelDynVocal(null, aiagentNsfwRelDynVocal('Ashe'), 'the consent bridge switched off takes RelDyn out of Sharmat\'s sight as a whole');

// read once per request per NPC
vocalWorld(['npc' => publishedAll($published)]);
aiagentNsfwRelDynVocal('Ashe'); aiagentNsfwRelDynVocal('ASHE'); aiagentNsfwRelDynVocalPace('Ashe'); aiagentNsfwRelDynVocalLine('Ashe');
sameRelDynVocal(1, count(vocalQueries()), 'one database read per NPC per request');

// ---------------------------------------------------------------- 3. combining the reasons to be quiet

$ashe = aiagentNsfwRelDynVocalFromRow($published['Ashe']);
$lynly = aiagentNsfwRelDynVocalFromRow($published['Lynly Star-Sung']);
sameRelDynVocal(null, aiagentNsfwVocalSilenceChance(null, null), 'no reason to be quiet: no chance at all');
sameRelDynVocal(0.62, aiagentNsfwVocalSilenceChance($ashe, null), 'RelDyn alone');
sameRelDynVocal(0.7, aiagentNsfwVocalSilenceChance(null, 0.7), 'the speak style alone');
sameRelDynVocal(0.8, aiagentNsfwVocalSilenceChance(['silence_chance' => 0.5], 0.6), 'two independent reasons: the chance to speak is the product (0.5 x 0.4)');
sameRelDynVocal(0.9, aiagentNsfwVocalSilenceChance(['silence_chance' => 0.9], 0.0), 'a style that sets 0 leaves RelDyn\'s chance alone');
sameRelDynVocal(aiagentNsfwVocalSilenceCeiling(), aiagentNsfwVocalSilenceChance(['silence_chance' => 0.92], 0.9), 'held under the ceiling');
sameRelDynVocal(0.0, aiagentNsfwVocalSilenceChance(null, 0), 'a style that sets 0: no silence');
sameRelDynVocal(null, aiagentNsfwVocalSilenceChance(null, ''), 'a blank style value sets nothing');
sameRelDynVocal(null, aiagentNsfwVocalSilenceChance(null, 'junk'), 'a junk style value sets nothing');
sameRelDynVocal(1.0 - 0.0, 1.0 - (0.0), 'sanity');

// ---------------------------------------------------------------- 4. which beats may go quiet

$engaged = ['scene_phase' => 'engaged', 'intensity_tier' => 3, 'accepted_sex' => true, 'scene_is_idle' => false];
foreach (['ext_nsfw_sexcene', 'chatnf_sl', 'chatnf_sl_nr'] as $event) {
    sameRelDynVocal(true, aiagentNsfwVocalSilenceApplies($event, $engaged), "{$event} in an accepted explicit scene may go quiet");
    sameRelDynVocal(true, aiagentNsfwVocalSilenceApplies($event, array_replace($engaged, ['scene_phase' => 'accepted'])), "{$event} just after acceptance too");
}
foreach (['ext_nsfw_orgasm', 'chatnf_sl_climax', 'ext_nsfw_npc_orgasm', 'chatnf_sl_moan', 'chatnf_sl_end', 'ext_nsfw_consent_bark', 'inputtext', 'rechat', 'ext_nsfw_action'] as $event) {
    sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies($event, $engaged), "{$event} is never silenced");
}
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_sexcene', array_replace($engaged, ['scene_phase' => 'tier_prompt'])), 'the accept or refuse turn always speaks');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_sexcene', array_replace($engaged, ['scene_phase' => 'rejected'])), 'a rejected scene is not a quiet one');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_sexcene', array_replace($engaged, ['accepted_sex' => false])), 'not accepted: speaks');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_sexcene', array_replace($engaged, ['refusal_expressed' => true])), 'a refusal is always voiced');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_sexcene', array_replace($engaged, ['request_scene_stop' => true])), 'a request to stop is always voiced');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_sexcene', array_replace($engaged, ['scene_is_idle' => true])), 'an idle beat is not a sex beat');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_sexcene', array_replace($engaged, ['intensity_tier' => 2])), 'a kiss is not bed talk');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_sexcene', $engaged, true, false), 'a slave\'s speech is their role');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_sexcene', $engaged, false, true), 'a paid service\'s speech is their role');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_sexcene', []), 'no scene state: speaks');
// NPC-to-NPC scenes
$npcScene = ['is_npc_scene' => true, 'sex_started' => true, 'scene_phase' => 'engaged'];
sameRelDynVocal(true, aiagentNsfwVocalSilenceApplies('ext_nsfw_npc_scene', $npcScene), 'an NPC scene stage update may go quiet');
sameRelDynVocal(true, aiagentNsfwVocalSilenceApplies('chatnf_npc_sl', $npcScene), 'and an NPC scene talk trigger');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_npc_orgasm', $npcScene), 'an NPC orgasm never');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_npc_scene', array_replace($npcScene, ['sex_started' => false])), 'not started: speaks');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_npc_scene', array_replace($npcScene, ['scene_is_idle' => true])), 'idle: speaks');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_npc_invite', $npcScene), 'the invite phase is not a sex beat');
sameRelDynVocal(false, aiagentNsfwVocalSilenceApplies('ext_nsfw_sexcene', $npcScene), 'a player-scene event in an NPC scene state: speaks');

// ---------------------------------------------------------------- 5. one beat

$d = aiagentNsfwVocalSilenceDecide(0.62, 0.30, 0, 8);
checkRelDynVocal($d['silent'] === true && $d['streak'] === 1 && $d['forced'] === false, 'a draw under the chance is silent: ' . json_encode($d));
$d = aiagentNsfwVocalSilenceDecide(0.62, 0.90, 3, 8);
checkRelDynVocal($d['silent'] === false && $d['streak'] === 0, 'a draw over the chance speaks and ends the streak: ' . json_encode($d));
$d = aiagentNsfwVocalSilenceDecide(0.62, 0.10, 8, 8);
checkRelDynVocal($d['silent'] === false && $d['forced'] === true && $d['streak'] === 0, 'after a long quiet stretch the NPC speaks whatever the draw: ' . json_encode($d));
$d = aiagentNsfwVocalSilenceDecide(0.62, 0.10, 7, 8);
checkRelDynVocal($d['silent'] === true && $d['streak'] === 8, 'the seventh is still quiet: ' . json_encode($d));
$d = aiagentNsfwVocalSilenceDecide(null, 0.0, 5, 8);
checkRelDynVocal($d['silent'] === false, 'no chance: speaks');
$d = aiagentNsfwVocalSilenceDecide(0.0, 0.0, 0, 8);
checkRelDynVocal($d['silent'] === false, 'a chance of 0 never silences');
$d = aiagentNsfwVocalSilenceDecide(0.5, 0.5, 0, 8);
checkRelDynVocal($d['silent'] === false, 'a draw equal to the chance speaks (the draw is under, not at)');
$d = aiagentNsfwVocalSilenceDecide(0.5, 0.2, 0, 0);
checkRelDynVocal($d['silent'] === true, 'a streak limit of 0 is read as 1, not as never silent');

// ---------------------------------------------------------------- 6. the whole gate, the beds, and the long run

$streakFile = sys_get_temp_dir() . '/reldyn_vocal_test_streak_' . getmypid() . '.txt';
@unlink($streakFile);
vocalWorld(['npc' => publishedAll($published)]);

// not applicable: null, and no streak file is touched
sameRelDynVocal(null, aiagentNsfwVocalSilenceGate('Ashe', 'ext_nsfw_orgasm', $engaged, false, false, null, 0.0, $streakFile), 'an orgasm beat: the gate has no opinion');
checkRelDynVocal(!is_file($streakFile), 'and keeps no streak');
sameRelDynVocal(null, aiagentNsfwVocalSilenceGate('Nobody', 'ext_nsfw_sexcene', $engaged, false, false, null, 0.0, $streakFile), 'an NPC RelDyn knows nothing of and no style chance: no opinion');

// the beds over a long scene (the same draws for each): who is quiet is who they are
mt_srand(20261001);
$draws = [];
for ($i = 0; $i < 4000; $i++) { $draws[] = mt_rand() / (mt_getrandmax() + 1); }
$silent = [];
$longest = [];
foreach (['Ashe', 'Aela the Huntress', 'Muiri', 'Lynly Star-Sung'] as $npc) {
    @unlink($streakFile);
    $count = 0; $run = 0; $maxRun = 0;
    foreach ($draws as $roll) {
        $g = aiagentNsfwVocalSilenceGate($npc, 'ext_nsfw_sexcene', $engaged, false, false, null, $roll, $streakFile, 8);
        if ($g['silent']) { $count++; $run++; $maxRun = max($maxRun, $run); } else { $run = 0; }
    }
    $silent[$npc] = $count / count($draws);
    $longest[$npc] = $maxRun;
}
$expect = ['Ashe' => 0.62, 'Aela the Huntress' => 0.40, 'Muiri' => 0.37, 'Lynly Star-Sung' => 0.18];
foreach ($expect as $npc => $p) {
    checkRelDynVocal($silent[$npc] <= $p + 0.01 && $silent[$npc] >= $p - 0.06, "{$npc}: quiet on about {$p} of beats (the streak limit takes a little off): " . round($silent[$npc], 3));
    checkRelDynVocal($longest[$npc] <= 8, "{$npc}: never more than 8 silent beats in a row: {$longest[$npc]}");
}
checkRelDynVocal($silent['Ashe'] > $silent['Aela the Huntress'] + 0.15 && $silent['Aela the Huntress'] > $silent['Lynly Star-Sung'] + 0.15, 'meaningful divergence between the beds: ' . json_encode($silent));
checkRelDynVocal($silent['Lynly Star-Sung'] > 0.05, 'and even the most talkative is quiet now and then');
checkRelDynVocal($silent['Ashe'] < 0.95, 'while even the quietest speaks');

// the streak is kept per NPC in its file, and a forced line resets it
@unlink($streakFile);
$run = [];
for ($i = 0; $i < 9; $i++) { $g = aiagentNsfwVocalSilenceGate('Ashe', 'ext_nsfw_sexcene', $engaged, false, false, null, 0.0, $streakFile, 8); $run[] = $g['silent'] ? 'q' : ($g['forced'] ? 'F' : 's'); }
sameRelDynVocal('qqqqqqqqF', implode('', $run), 'draws that are always under the chance: eight quiet beats and then the forced line');
sameRelDynVocal('0', trim((string)file_get_contents($streakFile)), 'and the streak starts again');
@unlink($streakFile);

// a style's own chance and RelDyn's combine in the gate
vocalWorld(['npc' => publishedAll($published)]);
$g = aiagentNsfwVocalSilenceGate('Lynly Star-Sung', 'ext_nsfw_sexcene', $engaged, false, false, 0.5, 0.0, null);
checkRelDynVocal(abs($g['chance'] - (1 - (0.82 * 0.5))) < 1e-9, 'the chance is the combination: ' . json_encode($g));
// a slave is never silenced, whatever is published
sameRelDynVocal(null, aiagentNsfwVocalSilenceGate('Ashe', 'ext_nsfw_sexcene', $engaged, true, false, 0.9, 0.0, null), 'a slave: no opinion');

// the wiring is text: the gate sits behind its switch, and the speak style carries the field
$prerequest = (string)file_get_contents(dirname(__DIR__) . '/prerequest.php');
checkRelDynVocal(strpos($prerequest, "_getNsfwSetting('NSFW_VOCAL_SILENCE', true)") !== false, 'the gate is behind NSFW_VOCAL_SILENCE');
checkRelDynVocal(strpos($prerequest, "'silence_chance'") !== false, 'the gate reads the speak style\'s silence_chance');
$data = (string)file_get_contents(dirname(__DIR__) . '/nsfw_data.php');
checkRelDynVocal(strpos($data, "'silence_chance' => (isset(\$styleData['silence_chance'])") !== false, 'getSpeakStyle returns the silence_chance field');
checkRelDynVocal(strpos($data, "'NSFW_VOCAL_SILENCE' => true") !== false && strpos($data, "'CARTESIA_SPEED_CLIMAX' => 0.6") !== false, 'the new settings have shipped defaults');
$cfgm = (string)file_get_contents(dirname(__DIR__) . '/config_manager.php');
checkRelDynVocal(substr_count($cfgm, 'silence_chance') >= 4, 'the speak style editor carries the field both ways');
checkRelDynVocal(strpos($cfgm, 'modalSilenceChance') !== false, 'with a field in the editor');

if ($failures) {
    fwrite(STDERR, "RelDyn vocal bridge regression tests FAILED (" . count($failures) . " of {$assertions}):\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "RelDyn vocal bridge regression tests passed ({$assertions} assertions).\n";
