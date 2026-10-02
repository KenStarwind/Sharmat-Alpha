<?php

/**
 * Scene awareness policy (LOCAL: branch reldyn-scenes of Ken's checkout, never pushed or proposed upstream; Ken 2026-10-01,
 * Wondernutts does not mind the edits).
 *
 * Pure of CHIM (no database, no globals), so tests/scene_awareness_test.php loads this file alone. Three small jobs the
 * OStim scene path needed:
 *
 *   1. A scene the import does not know (a pack scene that was never imported: about half of what OStim sends) used to read
 *      "are having an intimate moment", so a partner alternated between knowing what was going on and having no idea.
 *      aiagentNsfwSceneTagDescription() builds the line from the scene's own tags (they come with every event).
 *   2. OStim's hub scenes (waiting nodes ending in Hub or Pack) are navigation, not positions. aiagentNsfwIsHubScene()
 *      names them so they stop producing "intimate moment" lines and stop overwriting the real position.
 *   3. The scene voice speeds (foreplay, sex, climax) never reached Cartesia, which has its own speed vocabulary.
 *      aiagentNsfwSceneVoicePhase() / aiagentNsfwSceneVoiceSpeed() / aiagentNsfwCartesiaSpeedValue() resolve the speed for
 *      the NPC who is speaking and put it in the form the active Cartesia model takes.
 *
 * Descriptions use the actors' names, never a pronoun: scenes are every pairing and every gender.
 */

// ---------------------------------------------------------------------------------------------------------------------------
// HUB / TRANSITION SCENES
// ---------------------------------------------------------------------------------------------------------------------------

/**
 * Is this OStim scene a hub (a waiting or navigation node) rather than a position? True for an id that ends in Hub or Pack,
 * optionally followed by a digit and the pairing letters (UndressingHub0MF) or a pose word (BathingHubStandingM), and for a
 * scene that carries the tag "hub". Checked against all of the scenes in data/scene_defaults.json: it matches the 97 hub and
 * pack ids there and nothing else.
 */
function aiagentNsfwIsHubScene($sceneName, $stageName = '', $tags = [])
{
    $pattern = '/(?:hub|pack)\d*(?:[mf]{1,4}|standing[mf]?)?$/i';
    foreach ([$sceneName, $stageName] as $id) {
        $id = strtr(trim((string)$id), ['_A1' => '']);
        if ($id !== '' && preg_match($pattern, $id) === 1) { return true; }
    }
    foreach ((array)$tags as $tag) {
        if (strtolower(trim((string)$tag)) === 'hub') { return true; }
    }
    return false;
}

/**
 * Split the OStim payload ("SceneName/tag,tag/StageName/Actor^roles/...") into what the hub test needs. Pure; null when the
 * payload has no scene name.
 *
 * @return array|null ['scene' => string, 'tags' => string[], 'stage' => string]
 */
function aiagentNsfwParseSceneEventHead($payload)
{
    $parts = explode('/', (string)$payload);
    $scene = trim((string)($parts[0] ?? ''));
    if ($scene === '') { return null; }
    $tags = [];
    foreach (explode(',', strtolower((string)($parts[1] ?? ''))) as $t) {
        $t = trim($t);
        if ($t !== '') { $tags[] = $t; }
    }
    return ['scene' => $scene, 'tags' => $tags, 'stage' => trim((string)($parts[2] ?? ''))];
}

// ---------------------------------------------------------------------------------------------------------------------------
// TAG-BASED SCENE DESCRIPTION
// ---------------------------------------------------------------------------------------------------------------------------

/**
 * Acts, by the canonical tag, in the order a description mentions them (penetrative first, then oral, manual, body, touch).
 * 'phrase' is what the people are doing, with no direction (OStim's tags do not say who does what to whom) and no pronoun.
 * 'aliases' are the other tag spellings that mean the same thing (OStim and SexLab vocabularies).
 */
function aiagentNsfwSceneTagActs()
{
    return [
        'doublepenetration' => ['phrase' => 'in a double penetration', 'aliases' => ['dp']],
        'vaginalsex'        => ['phrase' => 'having vaginal sex', 'aliases' => ['vaginal', 'vaginalpenetration', 'intercourse']],
        'analsex'           => ['phrase' => 'having anal sex', 'aliases' => ['anal', 'analpenetration']],
        'deepthroat'        => ['phrase' => 'in deepthroat oral sex', 'aliases' => []],
        'facefuck'          => ['phrase' => 'in rough oral sex, fucking a face', 'aliases' => []],
        'blowjob'           => ['phrase' => 'in oral sex, a blowjob', 'aliases' => ['fellatio', 'blowjobs']],
        'oral'              => ['phrase' => 'in oral sex', 'aliases' => ['oralsex']],
        'cunnilingus'       => ['phrase' => 'in oral sex, going down on a pussy', 'aliases' => []],
        'sixtynine'         => ['phrase' => 'in a 69', 'aliases' => ['69']],
        'analingus'         => ['phrase' => 'rimming', 'aliases' => ['rimming']],
        'handjob'           => ['phrase' => 'using hands to stroke a cock', 'aliases' => ['handjobs']],
        'vaginalfingering'  => ['phrase' => 'fingering a pussy', 'aliases' => []],
        'fingering'         => ['phrase' => 'fingering', 'aliases' => ['fingered']],
        'analfingering'     => ['phrase' => 'fingering an ass', 'aliases' => []],
        'rubbingclitoris'   => ['phrase' => 'rubbing a clit', 'aliases' => ['clitrubbing']],
        'boobjob'           => ['phrase' => 'in a titfuck', 'aliases' => ['titfuck', 'titjob', 'breastjob']],
        'footjob'           => ['phrase' => 'using feet on a cock', 'aliases' => []],
        'thighjob'          => ['phrase' => 'in a thighjob', 'aliases' => []],
        'assjob'            => ['phrase' => 'grinding a cock between cheeks', 'aliases' => []],
        'grinding'          => ['phrase' => 'grinding against each other', 'aliases' => ['grind']],
        'masturbation'      => ['phrase' => 'masturbating', 'aliases' => ['femalemasturbation', 'malemasturbation', 'masturbating']],
        'spanking'          => ['phrase' => 'spanking', 'aliases' => ['faceslapping']],
        'choking'           => ['phrase' => 'choking', 'aliases' => []],
        'hairpulling'       => ['phrase' => 'pulling hair', 'aliases' => []],
        'lickingnipples'    => ['phrase' => 'licking nipples', 'aliases' => ['suckingnipples', 'breastsucking']],
        'breastgroping'     => ['phrase' => 'groping breasts', 'aliases' => ['breastfondling']],
        'assgroping'        => ['phrase' => 'groping an ass', 'aliases' => ['groping']],
        'licking'           => ['phrase' => 'licking skin', 'aliases' => []],
        'frenchkissing'     => ['phrase' => 'making out', 'aliases' => ['makingout', 'makeout', 'frenchkiss']],
        'neckkissing'       => ['phrase' => 'kissing necks', 'aliases' => ['neckkiss']],
        'kissing'           => ['phrase' => 'kissing', 'aliases' => ['kiss']],
        'cuddling'          => ['phrase' => 'cuddling', 'aliases' => ['cuddle', 'snuggling', 'snuggle', 'spooning']],
        'hugging'           => ['phrase' => 'hugging', 'aliases' => ['hug', 'embrace', 'embracing']],
        'holdinghands'      => ['phrase' => 'holding hands', 'aliases' => ['holdinghand', 'holdhands', 'handholding']],
    ];
}

/**
 * Positions, by the canonical tag: 'phrase' completes "in the ..." / "in a ...", 'sex' marks the ones that are sex by
 * themselves (so a scene tagged only with one still reads as sex).
 */
function aiagentNsfwSceneTagPositions()
{
    return [
        'reversecowgirl' => ['phrase' => 'reverse cowgirl position', 'sex' => true, 'aliases' => ['reverse']],
        'cowgirl'        => ['phrase' => 'cowgirl position', 'sex' => true, 'aliases' => ['riding']],
        'doggystyle'     => ['phrase' => 'doggy style position', 'sex' => true, 'aliases' => ['doggy', 'doggy-style', 'rearentry']],
        'missionary'     => ['phrase' => 'missionary position', 'sex' => true, 'aliases' => []],
        'lotus'          => ['phrase' => 'lotus position', 'sex' => false, 'aliases' => []],
        'prone'          => ['phrase' => 'prone position', 'sex' => false, 'aliases' => ['pronebone']],
        'standing'       => ['phrase' => 'standing position', 'sex' => false, 'aliases' => []],
        'sitting'        => ['phrase' => 'seated position', 'sex' => false, 'aliases' => ['seated']],
        'lyingdown'      => ['phrase' => 'lying position', 'sex' => false, 'aliases' => ['lying']],
    ];
}

function aiagentNsfwSceneTagKey($tag)
{
    return preg_replace('/[^a-z0-9]/', '', strtolower((string)$tag));
}

/** alias => canonical for one of the two tables above. */
function aiagentNsfwSceneTagLookup(array $table)
{
    $map = [];
    foreach ($table as $canonical => $row) {
        $map[aiagentNsfwSceneTagKey($canonical)] = $canonical;
        foreach ((array)($row['aliases'] ?? []) as $a) { $map[aiagentNsfwSceneTagKey($a)] = $canonical; }
    }
    return $map;
}

function aiagentNsfwSceneTagJoin(array $items)
{
    $items = array_values($items);
    if (count($items) <= 1) { return $items[0] ?? ''; }
    $last = array_pop($items);
    return implode(', ', $items) . ' and ' . $last;
}

/**
 * A scene description from the scene's tags, for a scene id the import does not hold. Names only (placeholders
 * {actor0}, {actor1}, ... that buildActorDescriptions() fills), no pronoun, no direction the tags do not give.
 *
 * "{actor0} and {actor1} are having vaginal sex in the cowgirl position"
 * "{actor0}, {actor1} and {actor2} are in a double penetration"
 * "{actor0} and {actor1} are in the missionary position, and having sex"  (a sex position with no act tag)
 *
 * null when the tags name no act and no position (idle, intro, transition, standing apart and the like): the caller keeps
 * its generic line.
 *
 * @param string[] $tags        the scene's tags
 * @param int      $actorCount  how many people are in the scene (0 reads as two)
 * 'specific' is true when the tags name an act or a sex position (what the scene is), false for a position that says nothing
 * of what is done in it (standing, sitting): callers that need the line to say something keep their generic one then.
 *
 * @return array|null ['template' => string, 'acts' => string[], 'positions' => string[], 'kind' => 'act'|'position', 'specific' => bool]
 */
function aiagentNsfwSceneTagDescription(array $tags, $actorCount = 2, $maxActs = 3)
{
    $actCanon = aiagentNsfwSceneTagLookup(aiagentNsfwSceneTagActs());
    $posCanon = aiagentNsfwSceneTagLookup(aiagentNsfwSceneTagPositions());
    $acts = [];
    $positions = [];
    foreach ($tags as $tag) {
        $key = aiagentNsfwSceneTagKey($tag);
        if ($key === '') { continue; }
        if (isset($actCanon[$key])) { $acts[$actCanon[$key]] = true; }
        if (isset($posCanon[$key])) { $positions[$posCanon[$key]] = true; }
    }
    if (!$acts && !$positions) { return null; }

    // acts in the table's order (penetrative first), the most telling few
    $actTable = aiagentNsfwSceneTagActs();
    $posTable = aiagentNsfwSceneTagPositions();
    $actList = array_values(array_slice(array_keys(array_intersect_key($actTable, $acts)), 0, max(1, (int)$maxActs)));
    $posList = array_values(array_keys(array_intersect_key($posTable, $positions)));

    // the people present (OStim also runs solo scenes)
    $n = max(1, (int)$actorCount ?: 2);
    $slots = [];
    for ($i = 0; $i < $n; $i++) { $slots[] = '{actor' . $i . '}'; }
    $who = aiagentNsfwSceneTagJoin($slots);
    $verb = $n === 1 ? 'is' : 'are';

    $phrases = [];
    foreach ($actList as $a) { $phrases[] = $actTable[$a]['phrase']; }
    $what = aiagentNsfwSceneTagJoin($phrases);
    // every phrase begins with "in" or a gerund, so "are {phrase}" reads in each case
    $kind = $actList ? 'act' : 'position';

    if ($posList) {
        $posPhrase = $posTable[$posList[0]]['phrase'];
        $article = in_array($posList[0], ['standing', 'sitting', 'lyingdown', 'prone', 'lotus'], true) ? 'a' : 'the';
        if ($actList) {
            $text = "{$who} {$verb} {$what}, in {$article} {$posPhrase}";
        } else {
            $sex = !empty($posTable[$posList[0]]['sex']);
            $text = $sex
                ? "{$who} {$verb} having sex in {$article} {$posPhrase}"
                : "{$who} {$verb} in {$article} {$posPhrase}";
        }
    } else {
        $text = "{$who} {$verb} {$what}";
    }
    $sexPosition = $posList && !empty($posTable[$posList[0]]['sex']);
    return ['template' => $text, 'acts' => $actList, 'positions' => $posList, 'kind' => $kind, 'specific' => (bool)$actList || $sexPosition];
}

// ---------------------------------------------------------------------------------------------------------------------------
// SCENE VOICE SPEED (foreplay, sex, climax) AND CARTESIA
// ---------------------------------------------------------------------------------------------------------------------------

/** The speed (settings value, 1.0 = normal) of each scene phase when nothing is configured: foreplay, sex, climax. */
function aiagentNsfwSceneVoiceSpeedDefaults()
{
    return ['foreplay' => 0.8, 'sex' => 0.7, 'climax' => 0.6];
}

/**
 * Which voice phase the NPC who is speaking is in: 'climax' (an orgasm beat), 'sex' (in the scene), 'foreplay' (before the
 * scene proper), or null (not in a scene: the voice stays as configured).
 *
 * @param string    $event        the event name captured before the scene handlers rewrote it
 * @param int|null  $level        the NPC's intimacy level when arousal gating is on (0 none, 1 foreplay, 2 in the scene)
 * @param bool      $gatingOn     is arousal gating (isSexDisposalEnabled) on
 * @param bool      $sceneEvent   was this a scene event (the gating-off signal)
 */
function aiagentNsfwSceneVoicePhase($event, $level, $gatingOn, $sceneEvent)
{
    $event = (string)$event;
    $inScene = $gatingOn ? ((int)$level === 2) : (bool)$sceneEvent;
    $foreplay = $gatingOn ? ((int)$level === 1) : false;
    if (!$inScene && !$foreplay) { return null; }
    if (in_array($event, ['ext_nsfw_orgasm', 'chatnf_sl_climax', 'ext_nsfw_npc_orgasm', 'ext_nsfw_action'], true) && $inScene) { return 'climax'; }
    return $inScene ? 'sex' : 'foreplay';
}

/**
 * The configured speed for a phase. $settings are the XTTS_* / CARTESIA_* values of the aiagent_nsfw_settings row; the
 * XTTS_MODIFY_LEVEL1 / LEVEL2 switches turn their phases off here too (the scene voice switch is one switch for every
 * engine). $paceMult is the NPC's own pace from RelDyn's vocal style (1.0 without RelDyn), applied last. Clamped to what the
 * engines take. null: leave this NPC's voice alone.
 */
function aiagentNsfwSceneVoiceSpeed($phase, array $settings, $paceMult = 1.0)
{
    $d = aiagentNsfwSceneVoiceSpeedDefaults();
    $num = function ($key, $fallback) use ($settings) {
        return (isset($settings[$key]) && is_numeric($settings[$key]) && (float)$settings[$key] > 0) ? (float)$settings[$key] : $fallback;
    };
    $on = function ($key) use ($settings) {
        if (!array_key_exists($key, $settings)) { return true; }
        $v = $settings[$key];
        return !($v === false || $v === 0 || $v === '0' || $v === 'false');
    };
    switch ($phase) {
        case 'foreplay':
            if (!$on('XTTS_MODIFY_LEVEL1')) { return null; }
            $speed = $num('XTTS_SPEED_LEVEL1', $d['foreplay']);
            break;
        case 'sex':
            if (!$on('XTTS_MODIFY_LEVEL2')) { return null; }
            $speed = $num('XTTS_SPEED_LEVEL2', $d['sex']);
            break;
        case 'climax':
            if (!$on('XTTS_MODIFY_LEVEL2')) { return null; }
            $speed = $num('CARTESIA_SPEED_CLIMAX', $d['climax']);
            break;
        default:
            return null;
    }
    $pace = (is_numeric($paceMult) && (float)$paceMult > 0) ? (float)$paceMult : 1.0;
    return round(max(0.1, min(1.5, $speed * max(0.8, min(1.25, $pace)))), 3);
}

/**
 * The speed in the form the active Cartesia model takes. Models sonic-3.5 and later take a number from 0.6 to 1.5
 * (generation_config.speed); the older request form takes the words slowest, slow, normal, fast, fastest. A number is mapped
 * to the nearest word (a tie goes to the slower one: a scene is slowed, never rushed by rounding).
 */
function aiagentNsfwCartesiaSpeedValue($speed, $modelId)
{
    $speed = max(0.6, min(1.5, (float)$speed));
    if (preg_match('/^sonic-3\.[56](?:$|-)/', (string)$modelId) === 1) { return round($speed, 3); }
    $words = ['slowest' => 0.6, 'slow' => 0.8, 'normal' => 1.0, 'fast' => 1.2, 'fastest' => 1.5];
    $best = 'normal';
    $bestDist = INF;
    foreach ($words as $word => $v) {
        $dist = abs($speed - $v);
        if ($dist < $bestDist - 1e-9) { $best = $word; $bestDist = $dist; }
    }
    return $best;
}

/**
 * Put the scene voice speed for this phase into the Cartesia settings the TTS engine reads when it speaks
 * ($GLOBALS['TTS']['CARTESIA']['speed'], read by generateCartesiaTTS()). Per request, so per NPC: each NPC's own turn sets its
 * own phase and nothing carries to another NPC. Only touches settings the connector has already filled in (a half-built
 * Cartesia config would send the engine looking for its connector again, which resets them). Returns the value set, or null
 * when nothing was changed (not Cartesia's config, the switch for the phase is off, or no phase).
 */
function aiagentNsfwApplyCartesiaSceneSpeed($phase, array $settings, $paceMult = 1.0)
{
    if ($phase === null) { return null; }
    $cfg = $GLOBALS['TTS']['CARTESIA'] ?? null;
    if (!is_array($cfg) || trim((string)($cfg['API_KEY'] ?? '')) === '') { return null; }
    $speed = aiagentNsfwSceneVoiceSpeed($phase, $settings, $paceMult);
    if ($speed === null) { return null; }
    $value = aiagentNsfwCartesiaSpeedValue($speed, (string)($cfg['model_id'] ?? 'sonic-3'));
    $GLOBALS['TTS']['CARTESIA']['speed'] = $value;
    return $value;
}
