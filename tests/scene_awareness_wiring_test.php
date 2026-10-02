<?php

/**
 * Wiring harness for the LOCAL scene-awareness changes (branch reldyn-scenes): the real, edited getSceneDescription() and
 * nsfwSceneIntensityTier() out of scene_lookup.php (extracted with the tokenizer: the file itself needs the whole CHIM
 * bootstrap) against a fixture world, the Cartesia speed written into the settings the TTS engine reads, and the text of the
 * hooks in the handlers.
 *
 * Run: php tests/scene_awareness_wiring_test.php
 */

require_once dirname(__DIR__) . '/scene_awareness_policy.php';

$failures = [];
$assertions = 0;
function checkSceneWiring($condition, $message)
{
    global $failures, $assertions;
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
}
function sameSceneWiring($expected, $actual, $message)
{
    checkSceneWiring($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

// ---------------------------------------------------------------- the fixture world

$GLOBALS['fx'] = ['jsonb' => [], 'json' => [], 'inserted' => [], 'logged' => []];
function getGlobalPrompt($key) { return ''; }
function getJsonbSceneDescription($sceneId) { return $GLOBALS['fx']['jsonb'][$sceneId] ?? null; }
function getSceneData($sceneId) { return $GLOBALS['fx']['json'][$sceneId] ?? null; }
function buildDescriptionWithPlaceholders($sceneData) { return $sceneData['desc'] ?? null; }
function autoInsertSceneDescription($sceneId, $desc) { $GLOBALS['fx']['inserted'][$sceneId] = $desc; }
function logOstimScene($sceneId, $desc, $source = 'JSONB') { $GLOBALS['fx']['logged'][] = [$sceneId, $source]; }

function extractSceneFunctionSource($file, $name)
{
    $src = file_get_contents($file);
    $tokens = token_get_all($src);
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if (!(is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === $name)) { continue; }
        $code = '';
        $depth = 0;
        $started = false;
        for ($k = $i; $k < $n; $k++) {
            $t = $tokens[$k];
            $text = is_array($t) ? $t[1] : $t;
            $code .= $text;
            if ($text === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; $started = true; }
            elseif ($text === '}') { $depth--; if ($started && $depth === 0) { return $code; } }
        }
    }
    return null;
}
$lookup = dirname(__DIR__) . '/scene_lookup.php';
foreach (['getSceneDescription', 'buildActorDescriptions', 'nsfwSceneIntensityTier'] as $fn) {
    $source = extractSceneFunctionSource($lookup, $fn);
    checkSceneWiring($source !== null, "{$fn} must be found in scene_lookup.php");
    eval((string)$source);
}

$names = ['Lynly Star-Sung', 'Kaida'];
$generic = 'Lynly Star-Sung and Kaida are having an intimate moment';

// ---------------------------------------------------------------- 1. tag fallback in the lookup chain

// a scene the import lacks, with act tags: described from them, kept for editing, logged as TAG
$GLOBALS['fx'] = ['jsonb' => [], 'json' => [], 'inserted' => [], 'logged' => []];
$d = getSceneDescription('BDGBedCowgirl-3', $names, ['vaginalsex', 'cowgirl', 'idle']);
checkSceneWiring(strpos($d, 'Lynly Star-Sung and Kaida are having vaginal sex, in the cowgirl position') === 0, 'a scene the import lacks reads from its tags: ' . $d);
checkSceneWiring(strpos($d, 'intimate moment') === false, 'and is no longer the generic line');
checkSceneWiring(strpos($d, '(This scene is for context only.') !== false, 'the narration instruction still follows');
sameSceneWiring('{actor0} and {actor1} are having vaginal sex, in the cowgirl position', $GLOBALS['fx']['inserted']['BDGBedCowgirl-3'] ?? null, 'kept with placeholders for the scenes manager');
sameSceneWiring([['BDGBedCowgirl-3', 'TAG']], $GLOBALS['fx']['logged'], 'logged as a tag description');

// the import wins over the tags (a user's own description is never replaced)
$GLOBALS['fx'] = ['jsonb' => ['KnownScene-1' => '{actor0} and {actor1} slow dance'], 'json' => [], 'inserted' => [], 'logged' => []];
$d = getSceneDescription('KnownScene-1', $names, ['vaginalsex']);
checkSceneWiring(strpos($d, 'Lynly Star-Sung and Kaida slow dance') === 0, 'the stored description wins: ' . $d);
sameSceneWiring([], $GLOBALS['fx']['inserted'], 'and nothing is inserted over it');

// the OStim JSON path wins over the tags
$GLOBALS['fx'] = ['jsonb' => [], 'json' => ['JsonScene-1' => ['desc' => '{actor0} and {actor1} lie close']], 'inserted' => [], 'logged' => []];
$d = getSceneDescription('JsonScene-1', $names, ['blowjob']);
checkSceneWiring(strpos($d, 'Lynly Star-Sung and Kaida lie close') === 0, 'the JSON description wins: ' . $d);

// tags that name no act or position keep the generic line and insert nothing
foreach ([['idle'], ['transition', 'intro'], [], ['standing']] as $tags) {
    $GLOBALS['fx'] = ['jsonb' => [], 'json' => [], 'inserted' => [], 'logged' => []];
    $d = getSceneDescription('Unknown-9', $names, $tags);
    checkSceneWiring(strpos($d, $generic) === 0, 'tags [' . implode(',', $tags) . '] keep the generic line: ' . $d);
    sameSceneWiring([], $GLOBALS['fx']['inserted'], 'and insert nothing');
}
// no third argument at all: the old behaviour exactly
$GLOBALS['fx'] = ['jsonb' => [], 'json' => [], 'inserted' => [], 'logged' => []];
$d = getSceneDescription('Unknown-9', $names);
checkSceneWiring(strpos($d, $generic) === 0, 'called as before: the generic line');
// one person, no partner: the solo act, and the generic single-actor line without tags
$d = getSceneDescription('Solo-1', ['Lynly Star-Sung'], ['masturbation']);
checkSceneWiring(strpos($d, 'Lynly Star-Sung is masturbating') === 0, 'a solo scene: ' . $d);
$d = getSceneDescription('Solo-2', ['Lynly Star-Sung']);
checkSceneWiring(strpos($d, 'An intimate scene is happening') === 0, 'a solo scene with no tags keeps the generic line');
// a three-person scene
$d = getSceneDescription('Trio-1', ['Lynly Star-Sung', 'Kaida', 'Aela the Huntress'], ['doublepenetration']);
checkSceneWiring(strpos($d, 'Lynly Star-Sung, Kaida and Aela the Huntress are in a double penetration') === 0, 'a trio: ' . $d);
// role tags (the NPC scene path) describe the scene but are not stored: they are a guess from who does what
$GLOBALS['fx'] = ['jsonb' => [], 'json' => [], 'inserted' => [], 'logged' => []];
$d = getSceneDescription('NpcScene-1', $names, ['dom', 'vaginal', 'standing'], false);
checkSceneWiring(strpos($d, 'Lynly Star-Sung and Kaida are having vaginal sex, in a standing position') === 0, 'role tags describe the scene: ' . $d);
sameSceneWiring([], $GLOBALS['fx']['inserted'], 'without storing it');
// no pronoun anywhere in what the fallback writes
foreach (['vaginalsex', 'blowjob', 'footjob', 'masturbation', 'kissing', 'doggystyle'] as $tag) {
    $d = getSceneDescription('P-' . $tag, $names, [$tag]);
    checkSceneWiring(preg_match('/\b(she|he|her|hers|him|his|herself|himself)\b/i', $d) !== 1, "no pronoun for tag {$tag}: {$d}");
}

// ---------------------------------------------------------------- 2. the tier: a hub is no scene cue

sameSceneWiring(0, nsfwSceneIntensityTier(['vaginalsex'], 'AnubsFfmPack AnubsFfmPack_A1', 'AnubsFfmPack_A1'), 'a pack node is tier 0 even with an act tag on it');
sameSceneWiring(0, nsfwSceneIntensityTier([], 'MilkyFmWallHub', 'MilkyFmWallHub'), 'a hub is tier 0');
sameSceneWiring(0, nsfwSceneIntensityTier(['idle', 'hub'], 'Anything', 'Anything'), 'a hub tag is tier 0');
sameSceneWiring(3, nsfwSceneIntensityTier(['vaginalsex'], 'OStim2PMissionaryMF', 'OStim2PMissionaryMF'), 'a real position is unchanged');
sameSceneWiring(2, nsfwSceneIntensityTier(['kissing'], 'Kiss', 'Kiss'), 'affection is unchanged');
sameSceneWiring(0, nsfwSceneIntensityTier(['idle'], 'OStimStandingApart', 'OStimStandingApart'), 'idle is unchanged');

// ---------------------------------------------------------------- 3. the Cartesia speed lands where the engine reads it

$GLOBALS['TTS'] = [];
sameSceneWiring(null, aiagentNsfwApplyCartesiaSceneSpeed('sex', []), 'no Cartesia settings yet: nothing is built');
sameSceneWiring([], $GLOBALS['TTS'], 'and no half-config is left behind');
$GLOBALS['TTS'] = ['CARTESIA' => ['API_KEY' => 'k', 'model_id' => 'sonic-3.5', 'speed' => 'normal']];
sameSceneWiring(0.7, aiagentNsfwApplyCartesiaSceneSpeed('sex', []), 'the sex speed on a current model');
sameSceneWiring(0.7, $GLOBALS['TTS']['CARTESIA']['speed'], 'is what the engine will read');
sameSceneWiring(0.6, aiagentNsfwApplyCartesiaSceneSpeed('climax', []), 'the climax speed');
sameSceneWiring(0.8, aiagentNsfwApplyCartesiaSceneSpeed('foreplay', []), 'the foreplay speed');
sameSceneWiring(0.665, aiagentNsfwApplyCartesiaSceneSpeed('sex', [], 0.95), 'a slow-talking NPC');
sameSceneWiring('k', $GLOBALS['TTS']['CARTESIA']['API_KEY'], 'the rest of the settings are untouched');
$GLOBALS['TTS']['CARTESIA']['speed'] = 'normal';
sameSceneWiring(null, aiagentNsfwApplyCartesiaSceneSpeed(null, []), 'no phase: nothing changed');
sameSceneWiring(null, aiagentNsfwApplyCartesiaSceneSpeed('sex', ['XTTS_MODIFY_LEVEL2' => false]), 'the switch off: nothing changed');
sameSceneWiring('normal', $GLOBALS['TTS']['CARTESIA']['speed'], 'and the connector speed stands');
$GLOBALS['TTS']['CARTESIA']['model_id'] = 'sonic-3';
sameSceneWiring('slowest', aiagentNsfwApplyCartesiaSceneSpeed('sex', []), 'the older request form takes the nearest word');
sameSceneWiring('slowest', $GLOBALS['TTS']['CARTESIA']['speed'], 'written as the word');
unset($GLOBALS['TTS']);

// ---------------------------------------------------------------- 4. the hooks are where the design says

$read = function ($f) { return (string)file_get_contents(dirname(__DIR__) . '/' . $f); };
$common = $read('common.php');
checkSceneWiring(strpos($common, 'scene_awareness_policy.php') !== false, 'common.php requires the scene awareness policy');
checkSceneWiring(strpos($common, 'reldyn_vocal_policy.php') !== false, 'common.php requires the vocal policy');
checkSceneWiring(strpos($read('nsfw_ostim_handler.php'), 'getSceneDescription($sexStageName, $sceneActorSlots, $sexTags)') !== false, 'the scene handler hands the tags to the lookup');
checkSceneWiring(strpos($read('nsfw_npc_scene.php'), 'getSceneDescription($sceneID, $actorList, array_values(array_unique($roleTags)), false)') !== false, 'the NPC scene path hands the role tags to the lookup');
$pre = $read('preprocessing.php');
checkSceneWiring(strpos($pre, '"nsfw_blocked_hub"') !== false && strpos($pre, 'external_fast_commands"][]="nsfw_blocked_hub"') !== false, 'the hub block is a registered silent fast command');
checkSceneWiring(strpos($pre, 'aiagentNsfwIsHubScene($hubHead[\'scene\']') !== false && strpos($pre, 'aiagentNsfwIsSceneActiveForPreprocess()') !== false, 'a hub is dropped only mid-scene');
checkSceneWiring(strpos($pre, 'nsfw_blocked_hub') < strpos($pre, 'Scene dedup - OStim fires scene events repeatedly'), 'and before the scene dedup records it');
$req = $read('prerequest.php');
checkSceneWiring(strpos($req, 'aiagentNsfwApplyCartesiaSceneSpeed') !== false && strpos($req, "=== 'cartesia'") !== false, 'prerequest sets the Cartesia speed for a Cartesia voice only');
checkSceneWiring(strpos($req, 'aiagentNsfwVocalSilenceGate') !== false && strpos($req, "_getNsfwSetting('NSFW_VOCAL_SILENCE', true)") !== false, 'prerequest holds the vocal silence gate behind its switch');
checkSceneWiring(strpos($req, 'aiagentNsfwRelDynVocalLine') !== false, 'prerequest hands the model RelDyn\'s line about how the NPC sounds');
$tts = (getenv('CHIM_ROOT') ?: dirname(__DIR__, 2)) . '/tts/tts-cartesia.php';   // set CHIM_ROOT to a CHIM checkout to check the engine too
if (is_file($tts)) {
    // inside a CHIM tree: the engine really reads the key this extension writes, and the modern model really takes a number
    $engine = (string)file_get_contents($tts);
    checkSceneWiring(strpos($engine, '$GLOBALS["TTS"]["CARTESIA"]["speed"]') !== false, 'the engine reads TTS.CARTESIA.speed');
    checkSceneWiring(preg_match('/is_numeric\(\$speed\)\s*\?\s*max\(0\.6,\s*min\(1\.5/', $engine) === 1, 'and takes a number for the current models');
}

if ($failures) {
    fwrite(STDERR, "Scene awareness wiring tests FAILED (" . count($failures) . " of {$assertions}):\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "Scene awareness wiring tests passed ({$assertions} assertions).\n";
