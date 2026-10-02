<?php
/**
 * SCENE LOOKUP - OStim JSON Parser with JSONB Override
 * =====================================================
 * Implements dual lookup: JSONB first (user customization), JSON fallback (automatic)
 *
 * Architecture:
 * 1. Check JSONB conf_opts for custom descriptions (user customization)
 * 2. If NULL, parse OStim JSON files for automatic descriptions
 * 3. Auto-insert scenes for future user editing
 *
 * Storage: JSONB in conf_opts (key: aiagent_nsfw_scenes)
 * This follows Tyler's preferred pattern for queryable JSONB storage.
 */

require_once __DIR__ . '/nsfw_data.php';
require_once __DIR__ . '/common.php';

// ============================================
// ACTION TYPE DESCRIPTIONS (40+ types)
// ============================================
$GLOBALS['OSTIM_ACTION_DESCRIPTIONS'] = [
    // Penetrative
    'vaginalsex' => '{actor} is fucking {target}\'s pussy',
    'analsex' => '{actor} is fucking {target}\'s ass',
    'doublepenetration' => '{actor} and {actor2} are double penetrating {target}',

    // Oral
    'blowjob' => '{actor} is sucking {target}\'s cock',
    'deepthroat' => '{actor} is deepthroating {target}\'s cock',
    'facefuck' => '{target} is fucking {actor}\'s face',
    'cunnilingus' => '{actor} is eating {target}\'s pussy',
    'analingus' => '{actor} is rimming {target}\'s ass',
    'sixtynine' => '{actor} and {target} are in a 69',

    // Manual
    'handjob' => '{actor} is stroking {target}\'s cock',
    'fingering' => '{actor} is fingering {target}',
    'vaginalfingering' => '{actor} is fingering {target}\'s pussy',
    'analfingering' => '{actor} is fingering {target}\'s ass',
    'rubbingclitoris' => '{actor} is rubbing {target}\'s clit',
    'masturbation' => '{actor} is masturbating',
    'femalemasturbation' => '{actor} is touching herself',
    'malemasturbation' => '{actor} is stroking himself',

    // Body parts
    'boobjob' => '{actor} is titfucking {target}',
    'titfuck' => '{actor} is titfucking {target}',
    'footjob' => '{actor} is using her feet on {target}',
    'thighjob' => '{actor} is thighfucking {target}',
    'assjob' => '{actor} is grinding on {target}\'s cock',
    'grinding' => '{actor} is grinding against {target}',

    // Kissing/Romance
    'kissing' => '{actor} and {target} are kissing',
    'frenchkissing' => '{actor} and {target} are making out',
    'neckkissing' => '{actor} is kissing {target}\'s neck',
    'licking' => '{actor} is licking {target}',
    'lickingnipples' => '{actor} is licking {target}\'s nipples',
    'suckingnipples' => '{actor} is sucking {target}\'s nipples',

    // Bondage/BDSM
    'spanking' => '{actor} is spanking {target}',
    'choking' => '{actor} is choking {target}',
    'hairpulling' => '{actor} is pulling {target}\'s hair',
    'faceslapping' => '{actor} is slapping {target}\'s face',

    // Positions (generic)
    'missionary' => '{actor} is fucking {target} missionary',
    'doggystyle' => '{actor} is fucking {target} from behind',
    'cowgirl' => '{target} is riding {actor}',
    'reversecowgirl' => '{target} is riding {actor} reverse',
    'prone' => '{actor} is fucking {target} prone',
    'standing' => '{actor} is fucking {target} standing',
    'spooning' => '{actor} is spooning {target}',

    // Other
    'cuddling' => '{actor} and {target} are cuddling',
    'hugging' => '{actor} and {target} are embracing',
    'idle' => '{actor} and {target} are getting intimate',
    'foreplay' => '{actor} and {target} are in foreplay',
    'holdinghand' => '{actor} is holding {target}\'s hand',
    'breastsucking' => '{actor} is sucking {target}\'s breasts',
    'breastgroping' => '{actor} is groping {target}\'s breasts',
    'assgroping' => '{actor} is groping {target}\'s ass',

    // Fallback
    'default' => '{actor} and {target} are having sex'
];

// Scene intensity tier: 1=innocent affection (hug/hold), 2=romantic (kiss/cuddle/foreplay), 3=sexual (default)
// Covers BOTH OStim action tags and SexLab Animation.GetTags(); affection routes to non-explicit prompts.
function nsfwSceneIntensityTier($tags = [], $sceneName = '', $sceneId = null) {
    // Per-scene override wins (set via the scenes manager 'tier' field)
    if ($sceneId !== null && class_exists('NsfwData')) {
        $ovScene = NsfwData::getScene($sceneId);
        if (is_array($ovScene) && isset($ovScene['tier']) && in_array((int)$ovScene['tier'], [1, 2, 3], true)) {
            return (int)$ovScene['tier'];
        }
    }
    $tagSet = array_map('strtolower', array_map('trim', (array)$tags));
    $name = strtolower((string)$sceneName);
    // LOCAL (branch reldyn-scenes): a hub is a waiting node, not a position (see scene_awareness_policy.php): no scene cue
    if (function_exists('aiagentNsfwIsHubScene') && aiagentNsfwIsHubScene($sceneId, $sceneName, $tagSet)) { return 0; }
    // Explicit ACT tags force tier 3 (exact match, OStim + SexLab vocab). Positions (standing/spooning/missionary/etc) are NOT here - ambiguous, handled below.
    $explicitTags = [
        'vaginalsex','analsex','doublepenetration','blowjob','deepthroat','facefuck','cunnilingus','analingus','sixtynine',
        'handjob','fingering','vaginalfingering','analfingering','rubbingclitoris','masturbation','femalemasturbation','malemasturbation',
        'boobjob','titfuck','footjob','thighjob','assjob','grinding','spanking','choking','hairpulling','faceslapping',
        'breastsucking','breastgroping','assgroping','suckingnipples','lickingnipples','licking','foreplay',
        'vaginal','anal','oral','sex','penetration','penetrating','intercourse','fuck','fucking','fisting','fellatio','rimming','pegging',
        'creampie','cumshot','facial','bukkake','squirting','squirt','gangbang','dp','groping','masturbating','fingered','blowjobs'
    ];
    foreach ($explicitTags as $t) { if (in_array($t, $tagSet, true)) return 3; }
    // Explicit act in the NAME forces tier 3 (scene audit 2026-07-04): several pack scenes carry only an
    // 'idle' tag while the pose is a handjob/cunnilingus/grind (MLC table series, OA3PP HJ/fellatio) -
    // they classified tier 0 and blipped the idle/decision machinery mid-act.
    foreach (['cunnilingus','fellatio','blowjob','handjob','footjob','boobjob','titfuck','fingering','masturbat','doggy','missionary','cowgirl','sixtynine','deepthroat','grind','hj'] as $kw) {
        if (strpos($name, $kw) !== false) return 3;
    }
    // Romantic (tier 2): STRICTLY chaste kiss/cuddle only. Any explicit/ambiguous tag already returned 3 above; mood words (loving/sensual/foreplay/spooning) are intentionally NOT here so anything sexual stays tier 3.
    $romanticTags = ['kissing','kiss','frenchkissing','frenchkiss','neckkissing','neckkiss','makingout','makeout',
        'cuddling','cuddle','snuggling','snuggle','caressing','caress','nuzzling','nuzzle'];
    foreach ($romanticTags as $t) { if (in_array($t, $tagSet, true)) return 2; }
    foreach (['kiss','cuddl','snuggle','makeout','caress','nuzzl'] as $kw) { if (strpos($name, $kw) !== false) return 2; }
    // Innocent (tier 1): hug / hold hands / embrace / standing-holding. NON-explicit closeness beats.
    // CRITICAL (user report 2026-06-29): names like "OStim2PStandingHoldingMF" / hold-hands carry NO act tag and the
    // keyword was only 'holdhand' (not 'holding'), so they fell through to default-3 and the NPC talked explicitly
    // ("oh right there, the way you fill me up...") during a hug/hold-hands/standing beat. "holding"/"embrace" =
    // foreplay, not sex. Explicit act tags already returned 3 above, so a truly sexual holding pose is unaffected.
    $innocentTags = ['hugging','hug','embrace','embracing','holdinghand','holdinghands','holdhands','handholding','holding'];
    foreach ($innocentTags as $t) { if (in_array($t, $tagSet, true)) return 1; }
    foreach (['hug','embrace','holdhand','handhold','holding hand','holdhands','holding','standinghold','standing hold'] as $kw) { if (strpos($name, $kw) !== false) return 1; }
    // Idle / intro / transition / setup beats (standing apart, scene start, position changes) are NON-sexual
    // NON-scenes -> tier 0: no scene cue, just normal conversation. Real act tags already returned 3 above.
    // 'transition' (OStim move-between-positions beats) must NOT carry the explicit cue or sex talk leaks between beats.
    foreach (['idle','intro','transition'] as $t) { if (in_array($t, $tagSet, true)) return 0; }
    if (strpos($name, 'standingapart') !== false || strpos($name, 'standing apart') !== false) return 0;
    return 3;
}

// ============================================
// ORGASM CONTEXT DESCRIPTIONS
// ============================================
$GLOBALS['OSTIM_ORGASM_CONTEXTS'] = [
    'vaginalsex' => ['came inside {target}', 'filled {target} with cum', 'came deep in {target}\'s pussy'],
    'analsex' => ['came in {target}\'s ass', 'filled {target}\'s ass', 'came deep in {target}\'s ass'],
    'blowjob' => ['came in {target}\'s mouth', 'came down {target}\'s throat', 'filled {target}\'s mouth'],
    'deepthroat' => ['came down {target}\'s throat', 'filled {target}\'s throat'],
    'handjob' => ['came from {target}\'s touch', 'came in {target}\'s hand'],
    'boobjob' => ['came on {target}\'s tits', 'came between {target}\'s breasts'],
    'footjob' => ['came from {target}\'s feet', 'came on {target}\'s feet'],
    'cunnilingus' => ['came on {actor}\'s face', 'came while {actor} ate her out'],
    'fingering' => ['came on {actor}\'s fingers', 'came from {actor}\'s fingers'],
    'sixtynine' => ['came while 69ing with {target}'],
    'cowgirl' => ['came inside {target}', 'came while {target} rode'],
    'doggystyle' => ['came inside {target} from behind'],
    'masturbation' => ['came', 'climaxed'],
    'default' => ['had an intense orgasm', 'came hard', 'climaxed']
];

// ============================================
// SCENE INDEX CACHE
// ============================================
$GLOBALS['OSTIM_SCENE_INDEX'] = null;
$GLOBALS['OSTIM_INDEX_BUILT'] = false;

/**
 * Build index of all OStim scenes from JSONB database
 * Reads from conf_opts aiagent_nsfw_scenes
 */
function buildSceneIndex() {
    if ($GLOBALS['OSTIM_INDEX_BUILT']) {
        return $GLOBALS['OSTIM_SCENE_INDEX'];
    }

    $index = [];

    // Read scenes from JSONB database (conf_opts)
    if (isset($GLOBALS['db'])) {
        try {
            $row = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_scenes'");
            if ($row && !empty($row['value'])) {
                $allScenes = json_decode($row['value'], true);
                if (is_array($allScenes)) {
                    foreach ($allScenes as $sceneId => $sceneData) {
                        // Ensure scene has required data
                        if (!empty($sceneId)) {
                            $index[$sceneId] = [
                                'path' => 'jsonb',
                                'data' => is_array($sceneData) ? $sceneData : ['id' => $sceneId, 'description' => $sceneData]
                            ];
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("[NSFW-SceneLookup] Error loading scenes from JSONB: " . $e->getMessage());
        }
    }

    $GLOBALS['OSTIM_SCENE_INDEX'] = $index;
    $GLOBALS['OSTIM_INDEX_BUILT'] = true;

    error_log("[NSFW-SceneLookup] Built scene index with " . count($index) . " scenes from JSONB");

    return $index;
}

/**
 * Get scene data from JSON by scene ID
 */
function getSceneData($sceneId) {
    $index = buildSceneIndex();

    if (isset($index[$sceneId])) {
        return $index[$sceneId]['data'];
    }

    // Try partial match (some scene IDs may have suffixes)
    foreach ($index as $id => $info) {
        if (strpos($id, $sceneId) === 0 || strpos($sceneId, $id) === 0) {
            return $info['data'];
        }
    }

    return null;
}

/**
 * Build description from scene actions using placeholders
 */
function buildDescriptionWithPlaceholders($sceneData) {
    if (!$sceneData || !isset($sceneData['actions']) || empty($sceneData['actions'])) {
        return null;
    }

    $descriptions = [];
    $actions = $sceneData['actions'];

    foreach ($actions as $action) {
        $type = strtolower($action['type'] ?? 'default');
        $actor = $action['actor'] ?? 0;
        $target = $action['target'] ?? 1;

        // Get template for this action type
        $template = $GLOBALS['OSTIM_ACTION_DESCRIPTIONS'][$type]
                    ?? $GLOBALS['OSTIM_ACTION_DESCRIPTIONS']['default'];

        // Replace with placeholder format Tyler uses
        $desc = str_replace(
            ['{actor}', '{target}', '{actor2}'],
            ['{actor' . $actor . '}', '{actor' . $target . '}', '{actor2}'],
            $template
        );

        $descriptions[] = $desc;
    }

    // Combine multiple actions intelligently
    if (count($descriptions) == 1) {
        return $descriptions[0];
    } else if (count($descriptions) == 2) {
        return $descriptions[0] . ' while ' . $descriptions[1];
    } else {
        $last = array_pop($descriptions);
        return implode(', ', $descriptions) . ', and ' . $last;
    }
}

/**
 * Replace placeholders with actual actor names
 */
function buildActorDescriptions($description, $actorNames) {
    if (!$description || !$actorNames) {
        return $description;
    }

    // Replace {actor0}, {actor1}, etc. with real names
    for ($i = 0; $i < count($actorNames); $i++) {
        $description = str_replace('{actor' . $i . '}', $actorNames[$i], $description);
    }

    // Also handle generic {actor} and {target} if still present
    if (count($actorNames) >= 1) {
        $description = str_replace('{actor}', $actorNames[0], $description);
    }
    if (count($actorNames) >= 2) {
        $description = str_replace('{target}', $actorNames[1], $description);
    }

    return $description;
}

/**
 * Get custom description from JSONB storage
 * Returns null if not found or no custom description
 */
function getJsonbSceneDescription($sceneId) {
    $lang = $GLOBALS['CORE_LANG'] ?? 'en';
    $description = NsfwData::getSceneDescription($sceneId, $lang);

    if (!empty($description)) {
        return $description;
    }

    return null;
}

/**
 * Auto-insert scene into JSONB storage for future editing
 */
function autoInsertSceneDescription($sceneId, $generatedDescription) {
    // Check if already exists
    $existing = NsfwData::getScene($sceneId);

    if (!$existing) {
        // Insert with generated description as starting point
        NsfwData::saveScene(
            $sceneId,
            $generatedDescription,
            null,
            null,
            '[AUTO] ' . $generatedDescription
        );
        error_log("[NSFW-SceneLookup] Auto-inserted scene to JSONB: " . $sceneId);
    }
}

/**
 * MAIN FUNCTION: Get scene description with dual lookup
 *
 * Priority:
 * 1. JSONB storage (user customization)
 * 2. OStim JSON parsing (automatic)
 * 3. Generic fallback
 */
/**
 * Log scene lookup to dedicated OStim scenes log file
 */
function logOstimScene($sceneId, $description, $source = 'JSONB') {
    $logFile = __DIR__ . "/../../log/ostim_scenes.log";
    $timestamp = date("Y-m-d H:i:s");
    $logLine = "[$timestamp] [$source] ID: $sceneId | DESC: $description\n";
    file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

function getSceneDescription($sceneId, $actorNames = [], $sceneTags = [], $keepTagDescription = true) {
    // Instruction appended to all scene descriptions to prevent narration
    // Reads from user-configurable prompt setting
    $defaultInstruction = "(This scene is for context only. React emotionally to what's happening - don't describe or narrate the physical actions. Show, don't tell.)";
    $configuredInstruction = getGlobalPrompt('scene_context_instruction');
    $sceneInstruction = " " . ($configuredInstruction ?: $defaultInstruction);

    // 1. Check JSONB storage first (user customization wins)
    $jsonbDescription = getJsonbSceneDescription($sceneId);
    if ($jsonbDescription) {
        $finalDesc = buildActorDescriptions($jsonbDescription, $actorNames);
        logOstimScene($sceneId, $finalDesc, 'JSONB');
        return $finalDesc . $sceneInstruction;
    }

    // 2. Fall back to OStim JSON parsing
    $sceneData = getSceneData($sceneId);
    if ($sceneData) {
        $placeholderDesc = buildDescriptionWithPlaceholders($sceneData);
        if ($placeholderDesc) {
            // Auto-insert for future user editing
            autoInsertSceneDescription($sceneId, $placeholderDesc);

            $finalDesc = buildActorDescriptions($placeholderDesc, $actorNames);
            logOstimScene($sceneId, $finalDesc, 'JSON');
            return $finalDesc . $sceneInstruction;
        }
    }

    // 2.5 LOCAL (branch reldyn-scenes): the scene id was never imported (about half of what OStim sends), but its tags come
    // with every event. Describe the scene from them before giving up to the generic line, and keep it for editing like the
    // JSON path does. A scene whose tags name no act or position (idle, intro, transition) keeps the generic line.
    if (!empty($sceneTags) && function_exists('aiagentNsfwSceneTagDescription')) {
        $tagDesc = aiagentNsfwSceneTagDescription((array)$sceneTags, count($actorNames));
        if ($tagDesc && !empty($tagDesc['specific'])) {
            // kept for editing only when the tags are the scene's own; role tags (a guess from who does what) are not stored
            if ($keepTagDescription) { autoInsertSceneDescription($sceneId, $tagDesc['template']); }
            $finalDesc = buildActorDescriptions($tagDesc['template'], $actorNames);
            logOstimScene($sceneId, $finalDesc, 'TAG');
            return $finalDesc . $sceneInstruction;
        }
    }

    // 3. Generic fallback
    $fallback = count($actorNames) >= 2
        ? "{$actorNames[0]} and {$actorNames[1]} are having an intimate moment"
        : "An intimate scene is happening";

    logOstimScene($sceneId, $fallback, 'FALLBACK');
    return $fallback . $sceneInstruction;
}

/**
 * Get contextual orgasm message based on current scene actions
 */
function getOrgasmContext($sceneId, $orgasmerIndex, $actorNames = []) {
    $sceneData = getSceneData($sceneId);

    if (!$sceneData || !isset($sceneData['actions'])) {
        // Fallback
        $contexts = $GLOBALS['OSTIM_ORGASM_CONTEXTS']['default'];
        return $contexts[array_rand($contexts)];
    }

    // Find action involving the orgasmer
    $orgasmAction = null;
    foreach ($sceneData['actions'] as $action) {
        $actor = $action['actor'] ?? 0;
        $target = $action['target'] ?? 1;

        if ($actor == $orgasmerIndex || $target == $orgasmerIndex) {
            $orgasmAction = $action;
            break;
        }
    }

    if (!$orgasmAction) {
        $contexts = $GLOBALS['OSTIM_ORGASM_CONTEXTS']['default'];
        $context = $contexts[array_rand($contexts)];
    } else {
        $actionType = strtolower($orgasmAction['type'] ?? 'default');
        $contexts = $GLOBALS['OSTIM_ORGASM_CONTEXTS'][$actionType]
                    ?? $GLOBALS['OSTIM_ORGASM_CONTEXTS']['default'];
        $context = $contexts[array_rand($contexts)];
    }

    // Replace placeholders
    return buildActorDescriptions($context, $actorNames);
}

/**
 * Sanitize scene data from requestMessageForActor format
 * Strips the CHIM context prefix to get clean scene ID
 *
 * Input: "(Context location: X ,Hold: Y)#PLAYER_NAME#:SceneID/Actor0:Arousal/..."
 * Output: Clean scene ID and parsed data
 */
function sanitizeSceneData($rawData) {
    $result = [
        'sceneId' => null,
        'actors' => [],
        'rawData' => $rawData
    ];

    // Strip CHIM context prefix if present
    // Format: (Context location: X ,Hold: Y)#NAME#:data
    $data = $rawData;

    // Remove context prefix
    if (preg_match('/^\(Context[^)]*\)/', $data)) {
        $data = preg_replace('/^\(Context[^)]*\)/', '', $data);
    }

    // Remove player name prefix
    if (preg_match('/^#[^#]+#:/', $data)) {
        $data = preg_replace('/^#[^#]+#:/', '', $data);
    }

    // Also handle "ActorName:" prefix
    if (preg_match('/^[^:\/]+:/', $data)) {
        $data = preg_replace('/^[^:\/]+:/', '', $data);
    }

    // Now parse: SceneID/Actor0:Arousal/Actor1:Arousal or similar formats
    $parts = explode('/', trim($data));

    if (!empty($parts[0])) {
        $result['sceneId'] = trim($parts[0]);
    }

    // Parse actor data from remaining parts
    for ($i = 1; $i < count($parts); $i++) {
        $actorPart = trim($parts[$i]);
        if (empty($actorPart)) continue;

        // Format: ActorName:Arousal or just ActorName
        if (strpos($actorPart, ':') !== false) {
            list($name, $arousal) = explode(':', $actorPart, 2);
            $result['actors'][] = [
                'name' => trim($name),
                'arousal' => (int)$arousal
            ];
        } else {
            $result['actors'][] = [
                'name' => $actorPart,
                'arousal' => 0
            ];
        }
    }

    return $result;
}

/**
 * Get list of all indexed scene IDs (for dropdown population)
 */
function getAllSceneIds() {
    $index = buildSceneIndex();
    return array_keys($index);
}

?>
