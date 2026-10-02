<?php

/**
 * Regression harness for the LOCAL scene-awareness changes (branch reldyn-scenes): tag-based scene descriptions, hub scene
 * suppression and the scene voice speeds for Cartesia. Loads the pure scene_awareness_policy.php alone and runs it against
 * the real scene data shipped in data/scene_defaults.json.
 *
 * Run: php tests/scene_awareness_test.php
 */

require_once dirname(__DIR__) . '/scene_awareness_policy.php';

$failures = [];
$assertions = 0;
function checkSceneAwareness($condition, $message)
{
    global $failures, $assertions;
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
}
function sameSceneAwareness($expected, $actual, $message)
{
    checkSceneAwareness($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

// ------------------------------------------------------------------ hub scenes

$imported = json_decode((string)file_get_contents(dirname(__DIR__) . '/data/scene_defaults.json'), true);
checkSceneAwareness(is_array($imported) && count($imported) > 3000, 'the shipped scene data loads');
$hubIds = [];
foreach (array_keys($imported) as $id) {
    if (aiagentNsfwIsHubScene($id)) { $hubIds[] = $id; }
}
checkSceneAwareness(count($hubIds) === 97, 'exactly the 97 hub and pack scenes of the shipped data are hubs (got ' . count($hubIds) . ')');
foreach ($hubIds as $id) {
    checkSceneAwareness(preg_match('/hub|pack/i', $id) === 1, "{$id} is a hub only because it names one");
}
foreach (['AnubsFfmPack', 'MilkyFmWallHub', 'AnubsBGFmWallHub', 'OStim2PUndressingHub0MF', 'OStim1PBathingHubStandingM', 'DrG_furnitureLTN_Ben!Ch_Hub'] as $id) {
    checkSceneAwareness(aiagentNsfwIsHubScene($id), "{$id} is a hub");
}
foreach (['OStim2PMissionaryLegsStretchedMF', 'AnubsMf-3', 'NibblesSidewaysfuck-4', 'OStim2PStandingHoldingMF', 'Billyy3pffmdogside-8'] as $id) {
    checkSceneAwareness(!aiagentNsfwIsHubScene($id), "{$id} is a position, not a hub");
}
checkSceneAwareness(aiagentNsfwIsHubScene('SomeScene', 'SomeStage_A1Hub'), 'the stage name counts');
checkSceneAwareness(aiagentNsfwIsHubScene('SomeScene', 'Stage', ['idle', 'hub']), 'a hub tag counts');
checkSceneAwareness(!aiagentNsfwIsHubScene('', '', []), 'nothing is not a hub');
checkSceneAwareness(!aiagentNsfwIsHubScene('Hubert', '', ['kissing']), 'a name that merely starts with hub is not a hub');

$head = aiagentNsfwParseSceneEventHead('AnubsFfmPack/idle,hub/AnubsFfmPack_A1/Kaida^dom/Aela^sub');
sameSceneAwareness('AnubsFfmPack', $head['scene'], 'the scene name parses');
sameSceneAwareness(['idle', 'hub'], $head['tags'], 'the tags parse');
sameSceneAwareness('AnubsFfmPack_A1', $head['stage'], 'the stage parses');
sameSceneAwareness(null, aiagentNsfwParseSceneEventHead('/a/b'), 'an empty scene name is no head');

// ------------------------------------------------------------------ tag descriptions

$d = aiagentNsfwSceneTagDescription(['vaginalsex', 'cowgirl', 'idle'], 2);
sameSceneAwareness('{actor0} and {actor1} are having vaginal sex, in the cowgirl position', $d['template'], 'sex in a position');
sameSceneAwareness('act', $d['kind'], 'an act tag makes it an act description');

$d = aiagentNsfwSceneTagDescription(['doublepenetration', 'vaginalsex'], 3);
sameSceneAwareness('{actor0}, {actor1} and {actor2} are in a double penetration and having vaginal sex', $d['template'], 'three people, two acts');

$d = aiagentNsfwSceneTagDescription(['masturbation'], 1);
sameSceneAwareness('{actor0} is masturbating', $d['template'], 'one person');

$d = aiagentNsfwSceneTagDescription(['missionary'], 2);
sameSceneAwareness('{actor0} and {actor1} are having sex in the missionary position', $d['template'], 'a sex position alone reads as sex');
sameSceneAwareness('position', $d['kind'], 'a position-only description is marked so');
$d = aiagentNsfwSceneTagDescription(['standing'], 2);
sameSceneAwareness('{actor0} and {actor1} are in a standing position', $d['template'], 'a non-sex position makes no claim of sex');

$d = aiagentNsfwSceneTagDescription(['Kissing', 'hugging'], 2);
sameSceneAwareness('{actor0} and {actor1} are kissing and hugging', $d['template'], 'case does not matter; affection reads as affection');
$d = aiagentNsfwSceneTagDescription(['french_kissing'], 2);
sameSceneAwareness('{actor0} and {actor1} are making out', $d['template'], 'separators in a tag do not matter');
$d = aiagentNsfwSceneTagDescription(['fellatio'], 2);
sameSceneAwareness('{actor0} and {actor1} are in oral sex, a blowjob', $d['template'], 'SexLab spellings map to the same acts');
$d = aiagentNsfwSceneTagDescription(['oral'], 2);
sameSceneAwareness('{actor0} and {actor1} are in oral sex', $d['template'], 'a bare oral tag does not guess the direction');
$d = aiagentNsfwSceneTagDescription(['vaginal', 'anal', 'oral', 'handjob', 'kissing'], 2);
sameSceneAwareness(['vaginalsex', 'analsex', 'oral'], $d['acts'], 'at most three acts, the most telling first');

foreach ([['idle'], ['intro', 'idle'], ['transition'], [], ['hub'], ['dom', 'sub']] as $tags) {
    sameSceneAwareness(null, aiagentNsfwSceneTagDescription($tags, 2), 'no act or position in [' . implode(',', $tags) . '] gives no description');
}
$d = aiagentNsfwSceneTagDescription(['vaginalsex'], 0);
checkSceneAwareness(strpos($d['template'], '{actor1}') !== false && strpos($d['template'], '{actor2}') === false, 'an unknown count reads as two');

// no pronoun in anything the tags can produce
$all = [];
foreach (array_keys(aiagentNsfwSceneTagActs()) as $a) {
    foreach ([1, 2, 3] as $n) { $x = aiagentNsfwSceneTagDescription([$a], $n); $all[] = $x['template']; }
}
foreach (array_keys(aiagentNsfwSceneTagPositions()) as $p) {
    foreach ([1, 2, 3] as $n) { $x = aiagentNsfwSceneTagDescription([$p], $n); $all[] = $x['template']; }
}
foreach ($all as $text) {
    checkSceneAwareness(preg_match('/\b(she|he|her|hers|him|his|herself|himself)\b/i', $text) !== 1, "no gendered pronoun in: {$text}");
    checkSceneAwareness(preg_match('/^\{actor0\}/', $text) === 1, "starts with the first actor: {$text}");
}

// The description fills with real names and is indeed better than the generic fallback on a scene the import lacks
$template = aiagentNsfwSceneTagDescription(['blowjob', 'kissing'], 2)['template'];
$filled = str_replace(['{actor0}', '{actor1}'], ['Lynly Star-Sung', 'Kaida'], $template);
sameSceneAwareness('Lynly Star-Sung and Kaida are in oral sex, a blowjob and kissing', $filled, 'filled with names');

// How much of what OStim sends does this reach? Every tag set the shipped scenes carry would be described from its tags.
// (The shipped data holds descriptions, not tags: the sample below is the tag vocabulary the handler and tier code know.)
foreach (['vaginalsex,cowgirl', 'blowjob', 'doggystyle,vaginalsex', 'handjob,kissing', 'cunnilingus', 'sixtynine', 'boobjob', 'analsex,doggystyle', 'masturbation', 'footjob'] as $set) {
    checkSceneAwareness(aiagentNsfwSceneTagDescription(explode(',', $set), 2) !== null, "the tag set [{$set}] is described");
}

// ------------------------------------------------------------------ voice phase and speed

sameSceneAwareness('sex', aiagentNsfwSceneVoicePhase('ext_nsfw_sexcene', 2, true, true), 'in the scene, gating on');
sameSceneAwareness('foreplay', aiagentNsfwSceneVoicePhase('ext_nsfw_sexcene', 1, true, true), 'foreplay, gating on');
sameSceneAwareness(null, aiagentNsfwSceneVoicePhase('inputtext', 0, true, false), 'not in a scene, gating on');
sameSceneAwareness('sex', aiagentNsfwSceneVoicePhase('chatnf_sl', 0, false, true), 'gating off: a scene event is in the scene');
sameSceneAwareness(null, aiagentNsfwSceneVoicePhase('inputtext', 2, false, false), 'gating off: no scene event is not in a scene');
sameSceneAwareness('climax', aiagentNsfwSceneVoicePhase('ext_nsfw_orgasm', 2, true, true), 'an orgasm beat');
sameSceneAwareness('climax', aiagentNsfwSceneVoicePhase('chatnf_sl_climax', 0, false, true), 'an orgasm beat, gating off');
sameSceneAwareness('foreplay', aiagentNsfwSceneVoicePhase('ext_nsfw_orgasm', 1, true, true), 'an orgasm beat that is not yet in the scene proper is not a climax voice');

$defaults = aiagentNsfwSceneVoiceSpeedDefaults();
sameSceneAwareness(['foreplay' => 0.8, 'sex' => 0.7, 'climax' => 0.6], $defaults, 'the shipped speeds: foreplay 0.8, sex 0.7, climax 0.6');
sameSceneAwareness(0.8, aiagentNsfwSceneVoiceSpeed('foreplay', []), 'foreplay default');
sameSceneAwareness(0.7, aiagentNsfwSceneVoiceSpeed('sex', []), 'sex default');
sameSceneAwareness(0.6, aiagentNsfwSceneVoiceSpeed('climax', []), 'climax default');
sameSceneAwareness(0.65, aiagentNsfwSceneVoiceSpeed('sex', ['XTTS_SPEED_LEVEL2' => 0.65]), 'the configured sex speed');
sameSceneAwareness(0.5, aiagentNsfwSceneVoiceSpeed('climax', ['CARTESIA_SPEED_CLIMAX' => 0.5]), 'the configured climax speed');
sameSceneAwareness(null, aiagentNsfwSceneVoiceSpeed('sex', ['XTTS_MODIFY_LEVEL2' => false]), 'the scene voice switch off: voice left alone');
sameSceneAwareness(null, aiagentNsfwSceneVoiceSpeed('foreplay', ['XTTS_MODIFY_LEVEL1' => false]), 'the foreplay switch off');
sameSceneAwareness(0.7, aiagentNsfwSceneVoiceSpeed('sex', ['XTTS_MODIFY_LEVEL1' => false]), 'the foreplay switch does not turn off sex');
sameSceneAwareness(null, aiagentNsfwSceneVoiceSpeed('nothing', []), 'an unknown phase');
sameSceneAwareness(0.7, aiagentNsfwSceneVoiceSpeed('sex', ['XTTS_SPEED_LEVEL2' => 'junk']), 'a junk setting falls back to the default');
// the NPC's own pace (RelDyn) moves it a little, never far
sameSceneAwareness(0.665, aiagentNsfwSceneVoiceSpeed('sex', [], 0.95), 'a slower-talking NPC');
sameSceneAwareness(0.735, aiagentNsfwSceneVoiceSpeed('sex', [], 1.05), 'a quicker-talking NPC');
sameSceneAwareness(0.56, aiagentNsfwSceneVoiceSpeed('sex', [], 0.1), 'the pace is bounded (0.8 at the lowest)');
sameSceneAwareness(0.875, aiagentNsfwSceneVoiceSpeed('sex', [], 9.0), 'the pace is bounded (1.25 at the highest)');

// Cartesia: a number for the new models, the nearest word for the older request form
sameSceneAwareness(0.7, aiagentNsfwCartesiaSpeedValue(0.7, 'sonic-3.5'), 'sonic-3.5 takes the number');
sameSceneAwareness(0.7, aiagentNsfwCartesiaSpeedValue(0.7, 'sonic-3.6-2026-08'), 'a dated sonic-3.6 takes the number');
sameSceneAwareness(0.6, aiagentNsfwCartesiaSpeedValue(0.3, 'sonic-3.5'), 'bounded below at 0.6');
sameSceneAwareness(1.5, aiagentNsfwCartesiaSpeedValue(2.0, 'sonic-3.5'), 'bounded above at 1.5');
sameSceneAwareness('slowest', aiagentNsfwCartesiaSpeedValue(0.6, 'sonic-3'), 'climax speed on the older form');
sameSceneAwareness('slow', aiagentNsfwCartesiaSpeedValue(0.8, 'sonic-3'), 'foreplay speed on the older form');
sameSceneAwareness('slowest', aiagentNsfwCartesiaSpeedValue(0.7, 'sonic-3'), 'a tie between words goes to the slower one');
sameSceneAwareness('normal', aiagentNsfwCartesiaSpeedValue(1.0, 'sonic-english'), 'normal');
sameSceneAwareness('fast', aiagentNsfwCartesiaSpeedValue(1.25, 'sonic-3'), 'above normal');
sameSceneAwareness('slow', aiagentNsfwCartesiaSpeedValue(0.85, ''), 'an unknown model takes the older form');

if ($failures) {
    fwrite(STDERR, "Scene awareness regression tests FAILED (" . count($failures) . " of {$assertions}):\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "Scene awareness regression tests passed ({$assertions} assertions).\n";
