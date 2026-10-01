<?php

// This is called after NPC profile is loaded.
require_once(__DIR__."/common.php");

// A rechat slot that survives the in-scene throttle must never morph into a random narrator
// interjection (main.php rolls RANDOM_NARATION on rechat turns): the narration prompt is
// scene-blind and its line derails every participant response after it.
if (($GLOBALS["gameRequest"][0] ?? '') === 'rechat' && !empty($GLOBALS["RANDOM_NARATION"])) {
    $_nsfwSceneActiveFile = sys_get_temp_dir() . "/nsfw_scene_active.txt";
    $_nsfwSceneEndedFile  = sys_get_temp_dir() . "/nsfw_scene_ended.txt";
    $_nsfwSceneActiveTs = is_file($_nsfwSceneActiveFile) ? (int)(file_get_contents($_nsfwSceneActiveFile) ?: 0) : 0;
    $_nsfwSceneEndedTs  = is_file($_nsfwSceneEndedFile) ? (int)(file_get_contents($_nsfwSceneEndedFile) ?: 0) : 0;
    if ($_nsfwSceneActiveTs > 0 && (time() - $_nsfwSceneActiveTs) < 300 && $_nsfwSceneActiveTs >= $_nsfwSceneEndedTs) {
        $GLOBALS["RANDOM_NARATION"] = false;
    }
}

if (!function_exists('aiagentNsfwIsNpcSceneRuntimeEventForPrerequest')) {
function aiagentNsfwIsNpcSceneRuntimeEventForPrerequest($event)
{
    return in_array((string)$event, [
        'chatnf_sl', 'chatnf_sl_climax', 'chatnf_sl_moan', 'chatnf_sl_naked', 'chatnf_sl_end',
        'ext_nsfw_sexcene', 'ext_nsfw_action', 'ext_nsfw_scene', 'ext_nsfw_orgasm',
        'ext_nsfw_npc_scene', 'ext_nsfw_npc_invite', 'ext_nsfw_npc_orgasm', 'chatnf_npc_sl'
    ], true);
}
}

if (!function_exists('aiagentNsfwSetAuthoritativeRefusalCue')) {
function aiagentNsfwSetAuthoritativeRefusalCue($tag, $prompt, $turnRule = '')
{
    $prompt = trim((string)$prompt);
    if ($prompt === '') {
        return;
    }

    $turnRule = trim((string)$turnRule);
    if ($turnRule === '') {
        // Voice guard (UI-editable: 'refusal_voice_guard') folds into the lockdown turn-rule so EVERY refusal
        // turn drops her seductive/base voice, not only the speak-style cue. Speech-only; mechanical gates untouched.
        $rvGuard = trim((string)NsfwData::getPrompt('refusal_voice_guard'));
        if ($rvGuard === '') {
            $rvGuard = "Set aside any flirtatious, seductive, teasing, or playful manner - a refusal is a real boundary, not part of the scene. Do not word it as if you are enjoying it, giving in, or as if the encounter is continuing.";
        }
        $turnRule = "For this reply, give exactly one clear in-character refusal or boundary explanation. Do not answer as if the scene is accepted, pleasurable, or ongoing. " . $rvGuard . " Do not add separate scene narration.";
    }

    $GLOBALS["AIAGENTNSFW_REFUSAL_CUE"] = NsfwRelationship::wrapXml($tag, $prompt . "\n\n" . $turnRule);
    $GLOBALS["AIAGENTNSFW_REFUSAL_PLAYER_REQUEST"] = "";
    unset($GLOBALS["AIAGENTNSFW_TIER_CUE_OVERRIDE"]);
    unset($GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"]);
    unset($GLOBALS["AIAGENTNSFW_SCENE_PLAYER_REQUEST_OVERRIDE"]);
    unset($GLOBALS["AIAGENTNSFW_RECHAT_SEX"]);
}
}

if (!function_exists('aiagentNsfwClearNpcSceneRuntimeForPrerequest')) {
function aiagentNsfwClearNpcSceneRuntimeForPrerequest($actorName, $intimacyStatus, $reason)
{
    $actorName = trim((string)$actorName);
    if ($actorName === '') {
        return $intimacyStatus;
    }

    $playerName = trim((string)($GLOBALS["PLAYER_NAME"] ?? ''));
    $actors = [$actorName];
    if (is_array($intimacyStatus["scene_actors"] ?? null)) {
        $actors = array_merge($actors, $intimacyStatus["scene_actors"]);
    }
    if (!empty($intimacyStatus["npc_scene_partner"])) {
        $actors[] = $intimacyStatus["npc_scene_partner"];
    }

    $actors = array_values(array_unique(array_filter(array_map(function($name) use ($playerName) {
        $name = trim((string)$name);
        return $name !== '' && strcasecmp($name, $playerName) !== 0 && strcasecmp($name, 'Player') !== 0 ? $name : null;
    }, $actors))));

    $sexTypes = "'chatnf_sl','chatnf_sl_moan','chatnf_sl_naked','chatnf_sl_climax','chatnf_npc_sl',"
              . "'ext_nsfw_sexcene','ext_nsfw_orgasm','ext_nsfw_action','ext_nsfw_scene',"
              . "'ext_nsfw_npc_scene','ext_nsfw_npc_invite','ext_nsfw_npc_orgasm',"
              . "'prechat','rechat','narration'";

    foreach ($actors as $clearActor) {
        $status = getIntimacyForActor($clearActor);
        if (empty($status["is_npc_scene"]) && empty($status["scene_actors"]) && empty($status["current_scene_desc"])) {
            continue;
        }

        if (isset($GLOBALS["db"])) {
            $escapedActor = $GLOBALS["db"]->escape($clearActor);
            $GLOBALS["db"]->delete("eventlog", "type in ($sexTypes) and people like '%|$escapedActor|%'");
        }

        updateIntimacyForActor($clearActor, [
            "level" => 0,
            "sex_disposal" => 10,
            "orgasmed" => false,
            "sex_started" => false,
            "is_naked" => 0,
            "scene_phase" => null,
            "tier_prompt_sent" => null,
            "cached_tier_prompt" => "",
            "current_scene_desc" => null,
            "current_scene_name" => null,
            "current_scene_tags" => null,
            "accepted_sex" => false,
            "accepted_affection" => false,
            "had_sex_in_scene" => false,
            "refusal_expressed" => false,
            "forced_scene" => false,
            "framework_forced_scene" => false,
            "scene_player_is_victim" => false,
            "scene_actor_is_aggressor" => false,
            "scene_actor_is_victim" => false,
            "request_scene_stop" => false,
            "stop_command_sent" => false,
            "last_scene_stop_time" => null,
            "scene_stop_retry_count" => 0,
            "last_forced_refusal_scene_key" => null,
            "last_refusal_speech_time" => null,
            "last_refusal_speech_key" => null,
            "scene_is_idle" => null,
            "scene_start_time" => null,
            "scene_actors" => null,
            "raw_scene_actor_slots" => null,
            "actor_roles" => null,
            "my_role_tags" => [],
            "current_primary_partner" => null,
            "is_active_participant" => false,
            "show_normal_kinks" => false,
            "show_secret_kinks" => false,
            "is_transaction" => false,
            "transaction_client" => null,
            "negotiation_phase" => false,
            "ready_for_service" => false,
            "payment_pending" => false,
            "payment_pending_amount" => null,
            "payment_pending_service" => null,
            "payment_confirmed" => false,
            "payment_confirmed_amount" => null,
            "payment_confirmed_time" => null,
            "payment_service" => null,
            "payment_failed" => false,
            "payment_failure_reason" => null,
            "payment_failed_amount" => null,
            "payment_failed_time" => null,
            "service_completed" => false,
            "service_end_time" => null,
            "service_duration" => null,
            "is_npc_scene" => false,
            "npc_scene_partner" => null,
            "npc_scene_thread_id" => null,
            "npc_scene_id" => null,
            "npc_scene_player_distance" => null,
            "last_npc_scene_update_time" => null,
            "npc_affinity_gate_disabled" => false,
            "partner_affinity" => null,
            "partner_tier" => null,
            "npc_refusal_dialogue_only" => false
        ]);
    }

    @unlink(sys_get_temp_dir() . "/nsfw_scene_last_hash.txt");
    error_log("[AIAGENTNSFW] Cleared stale NPC scene runtime for " . implode(", ", $actors) . " ($reason)");

    return getIntimacyForActor($actorName);
}
}

if (!function_exists('aiagentNsfwClearStaleNpcSceneForPrerequest')) {
function aiagentNsfwClearStaleNpcSceneForPrerequest($actorName, $intimacyStatus, $currentEvent)
{
    if (empty($intimacyStatus["is_npc_scene"]) || !empty($intimacyStatus["pillow_talk_pending"])) {
        return $intimacyStatus;
    }
    if (aiagentNsfwIsNpcSceneRuntimeEventForPrerequest($currentEvent)) {
        return $intimacyStatus;
    }

    $staleSeconds = function_exists('_getNsfwSetting') ? (int)_getNsfwSetting('NPC_SCENE_STALE_SECONDS', 330) : 330;
    $staleSeconds = max(60, $staleSeconds);
    $lastSceneUpdate = (int)($intimacyStatus["last_npc_scene_update_time"] ?? 0);
    if ($lastSceneUpdate <= 0) {
        $lastSceneUpdate = (int)($intimacyStatus["scene_start_time"] ?? 0);
    }

    if ($lastSceneUpdate > 0 && (time() - $lastSceneUpdate) < $staleSeconds) {
        return $intimacyStatus;
    }

    $sceneActiveTime = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
    $markerFresh = $sceneActiveTime > 0 && (time() - $sceneActiveTime) < $staleSeconds;
    if ($lastSceneUpdate <= 0 && $markerFresh) {
        return $intimacyStatus;
    }

    $age = $lastSceneUpdate > 0 ? (time() - $lastSceneUpdate) . "s" : "unknown";
    return aiagentNsfwClearNpcSceneRuntimeForPrerequest($actorName, $intimacyStatus, "non-scene {$currentEvent}, last NPC scene update age {$age}");
}
}

if (!function_exists('aiagentNsfwClearEndedPlayerSceneForPrerequest')) {
function aiagentNsfwClearEndedPlayerSceneForPrerequest($actorName, $intimacyStatus, $currentEvent)
{
    if (!is_array($intimacyStatus) || !empty($intimacyStatus["is_npc_scene"]) || !empty($intimacyStatus["pillow_talk_pending"])) {
        return $intimacyStatus;
    }
    if (aiagentNsfwIsNpcSceneRuntimeEventForPrerequest($currentEvent)) {
        return $intimacyStatus;
    }

    $hasPlayerSceneRuntime = !empty($intimacyStatus["scene_actors"])
        || !empty($intimacyStatus["current_scene_desc"])
        || !empty($intimacyStatus["scene_phase"])
        || !empty($intimacyStatus["request_scene_stop"])
        || !empty($intimacyStatus["refusal_expressed"])
        || !empty($intimacyStatus["is_transaction"])
        || !empty($intimacyStatus["payment_confirmed"])
        || !empty($intimacyStatus["payment_pending"])
        || !empty($intimacyStatus["negotiation_phase"]);
    if (!$hasPlayerSceneRuntime) {
        return $intimacyStatus;
    }

    $sceneActiveTime = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
    $sceneEndedTime = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt") ?: 0);
    $sceneEndedAfterActive = ($sceneEndedTime > 0 && $sceneEndedTime >= $sceneActiveTime && (time() - $sceneEndedTime) < 900);
    if (!$sceneEndedAfterActive) {
        return $intimacyStatus;
    }

    return aiagentNsfwClearNpcSceneRuntimeForPrerequest($actorName, $intimacyStatus, "normal {$currentEvent} after total player-scene exit");
}
}

// Read animations/stages descriptions from file


// Main code
// Will update intimacyStatus every iteration here

$GLOBALS["EMOTEMOODS"].=",flirty";// Gonna track this mood to manage sex_disposal

// CONSENT BARK -> fast decision turn. The game fires "ext_nsfw_consent_bark" the moment a PLAYER scene turns
// explicit (tier 3). It is registered as a fast command (preprocessing.php) so it ALREADY bypassed the MAIN
// semaphore by now. Here - after the semaphore, before any scene handling (processInfoSexScene below) - rewrite it
// to a normal "ext_nsfw_sexcene" so it reuses the ENTIRE proven scene path: handleSceneUpdate sets the scene up,
// the consent gate strips tools to AcceptSex/RefuseSex, and the LLM produces the decision. Net effect: the accept/
// refuse decision fires instantly (no semaphore wait), with full scene context, and no player input required.
if (($GLOBALS["gameRequest"][0] ?? '') === "ext_nsfw_consent_bark") {
    $GLOBALS["AIAGENTNSFW_CONSENT_BARK"] = true;
    $GLOBALS["gameRequest"][0] = "ext_nsfw_sexcene";
    $gameRequest[0] = "ext_nsfw_sexcene";
    error_log("[AIAGENTNSFW] Consent bark received -> processing as a fast (semaphore-bypassed) ext_nsfw_sexcene decision turn");
}


// Check current intimacy level
$codeName = npcNameToCodename($GLOBALS["HERIKA_NAME"]);
$intimacyStatus=getIntimacyForActor($GLOBALS["HERIKA_NAME"]);

if (!isset($intimacyStatus["level"]))
    $intimacyStatus["level"]=0;

// Process AIAgentNSFW events
$aiagentNsfwPreSceneEventName = (string)($GLOBALS["gameRequest"][0] ?? '');
processInfoSexScene();

// IN-SCENE TALKATIVENESS (feature 2026-07-12): "Respond to Orgasms" unchecked = no spoken climax
// reaction. Gate AFTER processInfoSexScene so all orgasm state (orgasmed flags, arousal, pillow-talk
// eligibility, out-of-band teardown) is already processed; FORCE_STOP only stops the LLM turn (context.php).
// Event name captured BEFORE the call - handlers may rewrite gameRequest[0] (e.g. to ext_nsfw_action).
if (in_array($aiagentNsfwPreSceneEventName, ['ext_nsfw_orgasm', 'chatnf_sl_climax', 'ext_nsfw_npc_orgasm'], true)
    && !_getNsfwSetting('NSFW_SCENE_SPEAK_ON_ORGASM', true)) {
    $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
    error_log("[AIAGENTNSFW] Orgasm response suppressed (Respond to Orgasms off); state processed silently");
}

$preSideRouteActor = trim((string)($GLOBALS["HERIKA_NAME"] ?? ""));
if ($preSideRouteActor !== "") {
    $intimacyStatus = aiagentNsfwClearStaleNpcSceneForPrerequest(
        $preSideRouteActor,
        getIntimacyForActor($preSideRouteActor),
        $GLOBALS["gameRequest"][0] ?? ''
    );
    // PLAYER ROUTE: if OStim's end event was lost, the player scene never tore down (no chatnf_sl_end ->
    // no handleSceneEnd -> sex_started/scene_phase linger and she keeps sex-talking outside the scene).
    // Run the SAME full teardown chatnf_sl_end would: durable memory + chat strip + thread pull + pillow.
    // Only on non-scene turns; the method itself confirms staleness before firing.
    if (class_exists('NsfwOstimHandler')
        && method_exists('NsfwOstimHandler', 'endStalePlayerSceneIfNeeded')
        && !aiagentNsfwIsNpcSceneRuntimeEventForPrerequest($GLOBALS["gameRequest"][0] ?? '')) {
        if (NsfwOstimHandler::endStalePlayerSceneIfNeeded($preSideRouteActor)) {
            $intimacyStatus = getIntimacyForActor($preSideRouteActor);
        }
    }
    $intimacyStatus = aiagentNsfwClearEndedPlayerSceneForPrerequest(
        $preSideRouteActor,
        getIntimacyForActor($preSideRouteActor),
        $GLOBALS["gameRequest"][0] ?? ''
    );
}

processInfoPhysics();

processInfoVRItems();

processInfoFertility();

$physicsContactContext = NsfwPhysics::getLastContactContext($GLOBALS["HERIKA_NAME"] ?? "");
if (!empty($physicsContactContext)) {
    $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $physicsContactContext;
}

// Reload
$intimacyStatus=getIntimacyForActor($GLOBALS["HERIKA_NAME"]);
$currentEvent = $GLOBALS["gameRequest"][0] ?? '';
if (function_exists('aiagentNsfwReconcileRecentConsentCommandForActor')) {
    $intimacyStatus = aiagentNsfwReconcileRecentConsentCommandForActor(
        $GLOBALS["HERIKA_NAME"],
        $intimacyStatus,
        "prerequest:{$currentEvent}"
    );
}

// ============================================
// ORGASM EVENT - Load prompts then exit
// ============================================
// When orgasm fires, we ONLY want the climax_prompt from the speak style
// Load prompts.php (defines ext_nsfw_orgasm), then let handler take over
// ============================================
$isOrgasmEvent = in_array($currentEvent, ['ext_nsfw_orgasm', 'chatnf_sl_climax', 'ext_nsfw_npc_orgasm']);

if ($isOrgasmEvent) {
    // NOTE: Do NOT require_once prompts.php here — it would prevent prompts.php from
    // re-running after prompts/prompts.php resets $PROMPTS at global scope.
    // Instead, store the computed cue in $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"]
    // and let prompts.php (loaded via requireFilesRecursively) pick it up.

    $actorName     = $GLOBALS["HERIKA_NAME"] ?? '';
    $extended_data = NsfwNpcData::get($actorName);

    // PARSE ORGASM DATA FIRST — needed to correctly choose prompt type
    // Strip CHIM context prefix "(Context location: X)PlayerName:" to get raw OStim data
    $rawOrgasmData = $GLOBALS["gameRequest"][3] ?? '';
    $orgasmPayload = $rawOrgasmData;
    if (($cp = strpos($rawOrgasmData, ')')) !== false) {
        $afterParen = substr($rawOrgasmData, $cp + 1);
        if (($col = strpos($afterParen, ':')) !== false) {
            $orgasmPayload = substr($afterParen, $col + 1);
        }
    }
    // ext_nsfw_npc_orgasm carries a ^-delimited payload (orgasmer^partner^scene); player/NPC orgasm is /-delimited
    if ($currentEvent === 'ext_nsfw_npc_orgasm') {
        $orgasmParts   = explode('^', $orgasmPayload);
        $ostimOrgasmer = trim($orgasmParts[0] ?? '');
        $ostimPartner  = trim($orgasmParts[1] ?? '');
        $ostimActionType = trim($orgasmParts[3] ?? '');
    } else {
        $orgasmParts   = explode('/', $orgasmPayload);
        $ostimOrgasmer = trim($orgasmParts[0] ?? '');
        $ostimPartner  = trim($orgasmParts[3] ?? ''); // GetSexPartner() result from Papyrus
        $ostimActionType = trim($orgasmParts[4] ?? '');
    }

	    $playerName       = $GLOBALS["PLAYER_NAME"] ?? "Player";
	    $isPlayerOrgasm   = (strcasecmp($ostimOrgasmer, $playerName) === 0) || (strcasecmp($ostimOrgasmer, 'PLAYER_ORGASM') === 0); // SexLab marks the player's orgasm with a PLAYER_ORGASM prefix
	    $thisNpcIsPartner = (!empty($ostimPartner) && strcasecmp($actorName, $ostimPartner) === 0);
	    $orgIntimacy      = getIntimacyForActor($actorName);
	    if (function_exists('aiagentNsfwReconcileRecentConsentCommandForActor')) {
	        $orgIntimacy = aiagentNsfwReconcileRecentConsentCommandForActor(
	            $actorName,
	            $orgIntimacy,
	            "orgasm:{$currentEvent}",
	            $playerName
	        );
	    }
	    $orgFrameworkForcedAggressor = !empty($orgIntimacy["framework_forced_scene"])
	        && !empty($orgIntimacy["scene_player_is_victim"])
	        && !empty($orgIntimacy["scene_actor_is_aggressor"]);
	    if ($orgFrameworkForcedAggressor) {
	        $orgDefeatPrompt = trim((string)getGlobalPrompt('defeat_aggressor_scene'));
	        if ($orgDefeatPrompt === '') {
	            $orgDefeatPrompt = 'A defeat framework identified #NPC_NAME# as an aggressor and #PLAYER_NAME# as the victim. This dedicated defeat path overrides ordinary relationship tier, romance, orientation, payment, arousal, consent, AcceptSex, and RefuseSex instructions until this scene ends. Do not ask whether #PLAYER_NAME# wants the scene, do not act disgusted by your own initiation, do not refuse your own scene, and do not claim #PLAYER_NAME# initiated it. Stay in character as the aggressor. Use the core personality of #NPC_NAME# and the current physical scene, but ignore conflicting relationship and consent prompts.';
	        }
	        $orgDefeatPrompt = str_replace(
	            ['#NPC_NAME#', '#PLAYER_NAME#'],
	            [$actorName, $playerName],
	            $orgDefeatPrompt
	        );
	        $GLOBALS["HERIKA_PERSONALITY"] .= "\n<defeat_aggressor_scene>\n{$orgDefeatPrompt}\n</defeat_aggressor_scene>";
	        error_log("[AIAGENTNSFW] Dedicated defeat path retained for orgasm event on {$actorName}");
	    }
	    $orgSceneActorsForResolve = is_array($orgIntimacy["raw_scene_actor_slots"] ?? null)
        ? $orgIntimacy["raw_scene_actor_slots"]
        : (is_array($orgIntimacy["scene_actors"] ?? null) ? $orgIntimacy["scene_actors"] : []);
    $orgSceneIdForResolve = trim((string)($orgIntimacy["current_scene_name"] ?? ($orgIntimacy["npc_scene_id"] ?? '')));
    $resolvedOrgasmPartner = trim((string)$ostimPartner);
    if ($isPlayerOrgasm && ($resolvedOrgasmPartner === '' || strcasecmp($resolvedOrgasmPartner, $playerName) === 0)) {
        $resolvedOrgasmPartner = (strcasecmp($actorName, $playerName) !== 0) ? $actorName : '';
    }
    if ($resolvedOrgasmPartner === '' && class_exists('NsfwNpcScene')) {
        $resolveFor = ($ostimOrgasmer !== '' && strcasecmp($ostimOrgasmer, 'PLAYER_ORGASM') !== 0) ? $ostimOrgasmer : $actorName;
        $resolvedOrgasmPartner = NsfwNpcScene::resolvePrimaryPartnerForActor($resolveFor, $orgSceneActorsForResolve, $orgSceneIdForResolve);
    }
    if ($resolvedOrgasmPartner !== '') {
        $ostimPartner = $resolvedOrgasmPartner;
        $thisNpcIsPartner = (strcasecmp($actorName, $ostimPartner) === 0);
    }
    if ($currentEvent === 'ext_nsfw_npc_orgasm' && !empty($ostimPartner)) {
        $GLOBALS["AIAGENTNSFW_NPC_PARTNER_NAME"] = $ostimPartner;
        $GLOBALS["AIAGENTNSFW_SCENE_PARTNER_NAME"] = $ostimPartner;
        $orgIntimacy["is_npc_scene"] = true;
        $orgIntimacy["npc_scene_partner"] = $ostimPartner;
    }

    // SELECT CORRECT SPEAK STYLE PROMPT based on who is orgasming
    // isPlayerOrgasm=true  → player came inside this NPC → use partner_climax_prompt (NPC reacts to player)
    // isPlayerOrgasm=false → this NPC is orgasming → use climax_prompt (NPC's own orgasm)
    if (!$orgFrameworkForcedAggressor && isNpcSlave($actorName)) {
        require_once __DIR__ . "/nsfw_relationship.php";
        $slaveAff = getNpcAffinity($actorName);
        $slaveClimaxCue = $isPlayerOrgasm
            ? NsfwRelationship::getSlaveOwnerClimaxPrompt($slaveAff, $playerName)
            : NsfwRelationship::getSlaveClimaxPrompt($slaveAff, (!empty($ostimPartner) ? $ostimPartner : $playerName)); // NPC-NPC: name the actual partner, not the player
        if (!empty($slaveClimaxCue)) {
            $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"] = "<climax_instruction>\n{$slaveClimaxCue}\n</climax_instruction>";
        }
    } elseif (!$orgFrameworkForcedAggressor && !$isPlayerOrgasm && isProstitute($actorName)
        && function_exists('aiagentNsfwGetProstituteScenePrompt')
        && ($prostOrgasmCue = aiagentNsfwGetProstituteScenePrompt($actorName, 'orgasm_prompt')) !== '') {
        // PROSTITUTE'S OWN ORGASM: use her per-NPC orgasm line instead of her profile/speak-style climax.
        $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"] = "<climax_instruction>\n{$prostOrgasmCue}\n</climax_instruction>";
        error_log("[AIAGENTNSFW] ORGASM: Using per-NPC PROSTITUTE orgasm_prompt for $actorName");
    } elseif (!empty($extended_data["sex_speech_style"])) {
        $speakStyleData      = NsfwData::getSpeakStyle($extended_data["sex_speech_style"]);
        $climaxPrompt        = $speakStyleData['climax_prompt'] ?? '';
        $partnerClimaxPrompt = $speakStyleData['partner_climax_prompt'] ?? '';

        if ($isPlayerOrgasm && !empty($partnerClimaxPrompt)) {
            // Player orgasmed — NPC reacts to partner coming inside them
            $partnerClimaxPrompt = aiagentNsfwResolveSpeakStylePlaceholders(
                $partnerClimaxPrompt,
                $actorName,
                $orgIntimacy,
                ['orgasmer_name' => $playerName, 'primary_partner' => $playerName]
            );
            // ORGASM LOCATION (user directive 2026-07-01): tell her EXACTLY where the player finished, derived from the
            // OStim/SexLab act tag ($ostimActionType) + player sex, so her climax reaction reacts to that specific spot
            // (in her pussy / on her tits / on her face / etc.) instead of a generic or wrong one. Hardcoded mapping.
            $orgasmLoc = function_exists('aiagentNsfwOrgasmLocationPhrase')
                ? aiagentNsfwOrgasmLocationPhrase($ostimActionType, (int)($GLOBALS['PLAYER_SEX'] ?? 0) === 1)
                : '';
            $orgasmLocLine = ($orgasmLoc !== '')
                ? "\n({$playerName} just came {$orgasmLoc} - react to exactly THAT, do not describe a different act or a different spot.)"
                : '';
            $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"] = "<partner_climax_instruction>\n{$partnerClimaxPrompt}{$orgasmLocLine}\n</partner_climax_instruction>";
            error_log("[AIAGENTNSFW] ORGASM: Using partner_climax_prompt for $actorName (player orgasmed)" . ($orgasmLoc !== '' ? " [location: {$orgasmLoc}]" : ""));
        } elseif (!empty($climaxPrompt)) {
            // NPC is orgasming themselves
            $withWhomPrompt = !empty($ostimPartner) ? $ostimPartner : $playerName;
            $climaxPrompt = aiagentNsfwResolveSpeakStylePlaceholders(
                $climaxPrompt,
                $actorName,
                $orgIntimacy,
                ['orgasmer_name' => $actorName, 'primary_partner' => $withWhomPrompt, 'npc_partner' => $withWhomPrompt]
            );
            $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"] = "<climax_instruction>\n{$climaxPrompt}\n</climax_instruction>";
            error_log("[AIAGENTNSFW] ORGASM: Using climax_prompt for $actorName (NPC orgasmed with $withWhomPrompt)");
        }
    }

    // SCENE CONTEXT — inject who is orgasming with whom into personality
    $orgSceneDesc   = $orgIntimacy["current_scene_desc"] ?? null;
    $orgSceneActors = $orgIntimacy["scene_actors"] ?? [];
    $orgScenePhase  = $orgIntimacy["scene_phase"] ?? null;
    if ($currentEvent === 'ext_nsfw_npc_orgasm') {
        $orgIntimacy["is_npc_scene"] = true;
        if (!empty($ostimPartner)) {
            $orgIntimacy["npc_scene_partner"] = $ostimPartner;
        }

        $npcGlobalPrompt = trim((string)getGlobalPrompt('npc_global_context'));
        if ($npcGlobalPrompt === '') {
            $npcGlobalPrompt = "This is an NPC-to-NPC scene. #NPC_NAME# is the speaking NPC and #PRIMARY_PARTNER# is their scene partner. The player is not the scene partner unless #PLAYER_NAME# is explicitly listed as a participant. Use this NPC's own profile, relationship state, marriage or affair context, intoxication, speech style, and unlocked kinks.";
        }
        $npcGlobalPrompt = aiagentNsfwRenderNpcScenePrompt($npcGlobalPrompt, $actorName, $orgIntimacy);
        if ($npcGlobalPrompt !== '') {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n<npc_global_context>\n{$npcGlobalPrompt}\n</npc_global_context>";
            error_log("[AIAGENTNSFW] Injected NPC global context for NPC orgasm: {$actorName}");
        }

        $npcContextReminder = trim((string)getGlobalPrompt('npc_context_reminder'));
        if ($npcContextReminder === '') {
            $npcContextReminder = 'Stay anchored to the NPC-only scene with #PRIMARY_PARTNER#. React as #NPC_NAME# using your own speech style, current relationship context, and the current scene description. Keep it brief and in character.';
        }
        $npcContextReminder = aiagentNsfwRenderNpcScenePrompt($npcContextReminder, $actorName, $orgIntimacy);
        if ($npcContextReminder !== '') {
            $GLOBALS["AIAGENTNSFW_NPC_CONTEXT_REMINDER_TEXT"] = $npcContextReminder;
        }
    }
    // A COMPLETED paid prostitute service (client just orgasmed) is satisfied + consented, NOT a refusal -
    // the scene-stop/payment-consume it triggers must not flag this climax as a refused/unaccepted orgasm.
    $orgServiceJustCompleted = !empty($orgIntimacy["service_completed"]);
    $orgRefusedScene = (
            ($orgScenePhase === "rejected")
            || !empty($orgIntimacy["request_scene_stop"])
            || !empty($orgIntimacy["refusal_expressed"])
        ) && !$orgServiceJustCompleted;
    $orgConsentConfirmed = !empty($orgIntimacy["scene_actor_is_aggressor"])
        || (!empty($orgIntimacy["accepted_sex"]) && (!function_exists('aiagentNsfwRelTypeSexEligible') || aiagentNsfwRelTypeSexEligible($actorName))) // eligibility required to HOLD acceptance (fix 2026-07-01j)
        || !empty($orgIntimacy["npc_is_slave"])
        || (!empty($orgIntimacy["npc_is_prostitute"]) && (!empty($orgIntimacy["payment_confirmed"]) || $orgServiceJustCompleted || !empty($orgIntimacy["free_service"])))
        || (!empty($orgIntimacy["is_npc_scene"]) && !empty($orgIntimacy["npc_affinity_gate_disabled"]))
        // MODEL-DRIVEN CONSENT (fix 2026-07-01c, mirrors handleOrgasm ~2742): a non-prostitute with the scene
        // in accepted/engaged phase, sex underway, or at/above the UI scene-call affinity floor who has NOT
        // refused IS consenting. accepted_sex is legitimately unset on the Fond+ autonomy and model-driven
        // acceptance paths - without this arm every such orgasm fired the non-consent boundary cue.
        // ELIGIBILITY REQUIRED (fix 2026-07-01g): sex_started just means a scene is RUNNING - in a player-forced
        // scene on an unwilling NPC that is not consent. Model-driven consent only for rel-type-eligible NPCs.
        || (empty($orgIntimacy["npc_is_prostitute"])
            && function_exists('aiagentNsfwRelTypeSexEligible') && aiagentNsfwRelTypeSexEligible($actorName)
            && (
                in_array(($orgIntimacy["scene_phase"] ?? ''), ['accepted', 'engaged'], true)
                || !empty($orgIntimacy["sex_started"]) || !empty($orgIntimacy["had_sex_in_scene"])
                || (function_exists('getNpcAffinity') && (int)getNpcAffinity($actorName) >= (function_exists('aiagentNsfwSceneCallFloorFor') ? (int)aiagentNsfwSceneCallFloorFor($actorName) : (int)_getNsfwSetting('NSFW_SCENE_CALL_MIN_AFFINITY', 56)))));
    $orgConsentBlocked = !$orgConsentConfirmed && empty($orgIntimacy["is_npc_scene"]);

    if ($orgRefusedScene || $orgConsentBlocked) {
        $orgasmWho = $isPlayerOrgasm ? $playerName : (!empty($ostimOrgasmer) ? $ostimOrgasmer : $actorName);
        // ASSAULT WITNESS (fix 2026-07-01h): fire the 'forcing' broadcast HERE, not only from the RefuseSex
        // FUNCSERV - models narrate refusals without calling the tool (0 emissions ever before this). A
        // non-consented orgasm in a player scene is the unambiguous assault signal. Victim gate <=55 affinity.
        if (empty($orgIntimacy["is_npc_scene"]) && function_exists('aiagentNsfwEmitWitnessLine')
            && function_exists('getNpcAffinity') && (int)getNpcAffinity($actorName) <= 55) {
            aiagentNsfwEmitWitnessLine('forcing', $actorName, $playerName);
        }
        $orgLocked = function_exists('aiagentNsfwSceneExitLocked') && aiagentNsfwSceneExitLocked($actorName);
        $orgStopRetryDue = function_exists('aiagentNsfwSceneStopRetryDue') ? aiagentNsfwSceneStopRetryDue($orgIntimacy) : empty($orgIntimacy["stop_command_sent"]);
        // GUARD: only an actor in an ACTIVE scene (level>=1) may queue the player ExtCmdStopScene. A stray/late
        // orgasm for an actor whose scene already ended (level 0 - e.g. an NPC<->NPC scene that just closed) must
        // NOT queue a player stop, or it tears down whatever OStim scene the PLAYER is currently in. (Jala bug.)
        if (!$orgLocked && (int)($orgIntimacy["level"] ?? 0) >= 1 && $currentEvent !== "ext_nsfw_npc_orgasm" && $orgStopRetryDue && function_exists('aiagentNsfwQueuePlayerSceneStop')) {
            if (aiagentNsfwQueuePlayerSceneStop($actorName, 'ExtCmdStopScene')) {
                if (function_exists('aiagentNsfwMarkSceneStopQueued')) {
                    aiagentNsfwMarkSceneStopQueued($orgIntimacy);
                } else {
                    $orgIntimacy["stop_command_sent"] = true;
                }
            }
        }
        $orgIntimacy["scene_phase"] = "rejected";
        $orgIntimacy["accepted_sex"] = false;
        $orgIntimacy["request_scene_stop"] = !$orgLocked;  // exit-locked: refusal stands, scene continues, no hard stop
        $orgIntimacy["refusal_expressed"] = true;
        if (!$isPlayerOrgasm && strcasecmp($actorName, $ostimOrgasmer) === 0) {
            $orgIntimacy["orgasmed"] = true;
        }
        $orgRefusalKey = implode("|", [
            "orgasm",
            (string)$currentEvent,
            (string)$orgasmWho,
            md5((string)$rawOrgasmData . "|" . (string)($orgIntimacy["current_scene_name"] ?? ""))
        ]);
        $orgRefusalDue = function_exists('aiagentNsfwRefusalSpeechDue') ? aiagentNsfwRefusalSpeechDue($orgIntimacy, $orgRefusalKey) : true;
        if (!$orgRefusalDue) {
            updateIntimacyForActor($actorName, $orgIntimacy);
            $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
            error_log("[AIAGENTNSFW] ORGASM in refused scene for $actorName - suppressed refusal speech by ticker ($orgRefusalKey)");
            return;
        }
        if (function_exists('aiagentNsfwMarkRefusalSpeech')) {
            aiagentNsfwMarkRefusalSpeech($orgIntimacy, $orgRefusalKey);
        }
        $forcedOrgasmContext = $orgConsentBlocked
            ? "{$orgasmWho} had an orgasm during a scene you have not accepted. React through the consent boundary. Do not treat this as accepted sex, pleasure, approval, afterglow, or willingness."
            : "{$orgasmWho} had an orgasm during a scene you refused or wanted stopped. React to that fact in character. Keep the refusal and boundary-violation context active. Do not treat this as accepted sex.";
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n<forced_scene_orgasm>\n{$forcedOrgasmContext}\n</forced_scene_orgasm>";
        $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"] = "<forced_orgasm_instruction>\n{$forcedOrgasmContext}\n</forced_orgasm_instruction>";
        updateIntimacyForActor($actorName, $orgIntimacy);
        error_log("[AIAGENTNSFW] ORGASM in refused/unaccepted scene for $actorName - injected forced-orgasm refusal context");
    }

    if (!empty($orgSceneActors)) {
        $orgPartnerNames = array_filter($orgSceneActors, function($a) use ($actorName) {
            return strtolower($a) !== strtolower($actorName);
        });

        if ($isPlayerOrgasm) {
            // A usable partner = a real NPC name (not empty, not the player's own name). SexLab fans a player orgasm
            // to each partner with parts[3] = the PLAYER's name, so treat those recipients as the partner -> "with you".
            $partnerKnown = !empty($ostimPartner) && strcasecmp($ostimPartner, $playerName) !== 0;
            $withWhom = $partnerKnown ? $ostimPartner : (reset($orgPartnerNames) ?: $playerName);
            if (!$partnerKnown || $thisNpcIsPartner) {
                $orgSceneBlock = "<current_scene>\n{$playerName} is having an orgasm with you.";
            } else {
                $orgSceneBlock = "<current_scene>\n{$playerName} is having an orgasm with {$withWhom}. You are present in the scene.";
            }
        } else {
            $withWhom      = !empty($ostimPartner) ? $ostimPartner : $playerName;
            $orgSceneBlock = "<current_scene>\nYou are having an orgasm with {$withWhom}.";
        }

        if (!empty($orgSceneDesc)) {
            $orgSceneBlock .= "\nCurrent position: $orgSceneDesc";
        }
        $orgSceneTags = $orgIntimacy["current_scene_tags"] ?? [];
        if (!empty($orgSceneTags)) {
            $orgSceneBlock .= "\nScene tags: " . implode(", ", $orgSceneTags);
        }
        if (!empty($ostimActionType)) {
            $orgSceneBlock .= "\nOStim action: {$ostimActionType}.";
        }
        $orgSceneBlock .= "\n</current_scene>";
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $orgSceneBlock;

        // Resolve canonical orgasm placeholders in the cue override and chatnf_sl_climax.
        if (!empty($GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"])) {
            $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"] = aiagentNsfwResolveScenePlaceholders(
                $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"],
                $actorName,
                $orgIntimacy,
                ['orgasmer_name' => ($isPlayerOrgasm ? $playerName : $actorName), 'primary_partner' => $withWhom, 'npc_partner' => $withWhom]
            );
        }
        if (isset($GLOBALS["PROMPTS"]["chatnf_sl_climax"]["cue"][0])) {
            $GLOBALS["PROMPTS"]["chatnf_sl_climax"]["cue"][0] = aiagentNsfwResolveScenePlaceholders(
                $GLOBALS["PROMPTS"]["chatnf_sl_climax"]["cue"][0],
                $actorName,
                $orgIntimacy,
                ['orgasmer_name' => ($isPlayerOrgasm ? $playerName : $actorName), 'primary_partner' => $withWhom, 'npc_partner' => $withWhom]
            );
        }
    }

    error_log("[AIAGENTNSFW] ORGASM for $actorName — orgasmer: $ostimOrgasmer | partner: $ostimPartner | action: $ostimActionType | isPlayerOrgasm: " . ($isPlayerOrgasm ? 'yes' : 'no'));
    return;  // Exit - don't inject speech style or other stuff
}

// ============================================
// PILLOW TALK INJECTION (POST-ORGASM + SCENE END)
// ============================================
// Pillow talk requires BOTH: scene_end AND orgasm occurred
// Only NPCs who orgasmed during the scene get pillow_talk_pending set
// NPCs who didn't orgasm have their scene state cleared but no pillow talk
// CRITICAL: This MUST clear scene state to prevent sex prompts from firing
// ============================================
$isPillowTalkMode = false;  // Flag to skip sex prompt injection
if (!empty($intimacyStatus["pillow_talk_pending"]) && !empty($intimacyStatus["pillow_talk_prompt"])) {
    $pillowTalkPrompt = $intimacyStatus["pillow_talk_prompt"];
    // The scene-thread fence now clears scene context once the scene ends, so the old hardcoded
    // "scene ENDED, stop moaning" preamble is redundant. Inject only the pillow-talk content, which
    // itself comes from the NPC's UI speak style (source of truth), not a baked-in string.
    $GLOBALS["HERIKA_PERSONALITY"] .= "\n<post_scene_instruction>\n{$pillowTalkPrompt}\n</post_scene_instruction>";
    error_log("[AIAGENTNSFW] Injected pillow talk for {$GLOBALS["HERIKA_NAME"]}");

    // CLEAR ALL SCENE STATE to prevent sex prompts from firing
    $intimacyStatus["pillow_talk_pending"] = false;
    $intimacyStatus["pillow_talk_prompt"] = "";
    $intimacyStatus["level"] = 0;  // Force level 0 to disable sex prompts
    $intimacyStatus["scene_actors"] = null;  // Clear scene actors
    $intimacyStatus["scene_phase"] = null;  // Clear phase
    $intimacyStatus["skooma_addiction_bargain"] = false;
    $intimacyStatus["is_npc_scene"] = false;
    $intimacyStatus["npc_scene_partner"] = null;
    $intimacyStatus["last_forced_refusal_scene_key"] = null;
    updateIntimacyForActor($GLOBALS["HERIKA_NAME"], $intimacyStatus);

    $isPillowTalkMode = true;  // Skip all sex prompt injection below

    // Defer the cue override to prompts.php (loads LAST and rebuilds every cue, so a direct write here gets clobbered).
    // This is what actually stops late/queued sex events from reigniting the scene after it ended.
    $GLOBALS["AIAGENTNSFW_PILLOW_TALK_CUE"] = "<post_scene_instruction>\n{$pillowTalkPrompt}\n</post_scene_instruction>";

    error_log("[AIAGENTNSFW] Pillow talk mode active - deferred pillow cue override for {$GLOBALS["HERIKA_NAME"]}");
}

$intimacyStatus = aiagentNsfwClearStaleNpcSceneForPrerequest($GLOBALS["HERIKA_NAME"] ?? "", $intimacyStatus, $currentEvent);

if ($codeName == "the_narrator") {
    return; // Narrator updates scene data only — no LLM call, no log entries
}

// A scene request may have passed preprocessing before the player spoke, then sat
// behind MAIN. Kill stale NPC-to-NPC speech here before prompt assembly; orgasms
// already returned above and must keep firing.
$playerInterruptibleNpcSceneEvents = ['ext_nsfw_npc_scene', 'ext_nsfw_npc_invite', 'chatnf_npc_sl'];
if (in_array($currentEvent, $playerInterruptibleNpcSceneEvents, true)
    && function_exists('aiagentNsfwHasRecentPlayerInterruptForRequest')) {
    $interruptSuppressSeconds = max(1, (int)_getNsfwSetting('NPC_SCENE_PLAYER_INTERRUPT_SUPPRESS_SECONDS', 30));
    $requestTs = (float)($GLOBALS["gameRequest"][1] ?? 0);
    if (aiagentNsfwHasRecentPlayerInterruptForRequest($requestTs, $interruptSuppressSeconds)) {
        error_log("[AIAGENTNSFW] Terminated {$currentEvent} after MAIN for player interrupt; suppress {$interruptSuppressSeconds}s");
        $GLOBALS["gameRequest"][0] = "nsfw_blocked_cooldown";
        terminate();
    }
}

// ============================================
// NPC-TO-NPC SCENE/INVITE: DEFERRED PLACEHOLDER CONTEXT
// ============================================
// prompts.php rebuilds cue text after prerequest, so stash route context here
// and let the shared resolver finalize placeholders at the end.
// ============================================
$npcRouteEvents = ['ext_nsfw_npc_scene', 'ext_nsfw_npc_invite', 'ext_nsfw_npc_orgasm', 'chatnf_npc_sl'];
$isExplicitNpcPromptEvent = in_array($currentEvent, $npcRouteEvents, true);
$npcSceneDialogueEnabledForPrompt = function_exists('_getNsfwSetting') ? _getNsfwSetting('NPC_SCENE_LLM_ENABLED', true) : true;
$isNpcInviteRoute = ($currentEvent === 'ext_nsfw_npc_invite') || (($intimacyStatus["scene_phase"] ?? null) === 'invite');
$playerInputEvents = ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s', 'narrator_inputtext', 'narrator_inputtext_s'];
$isPlayerInputEvent = in_array($currentEvent, $playerInputEvents, true);

$npcPromptPartnerName = "";
if (!empty($intimacyStatus["is_npc_scene"]) && !empty($intimacyStatus["npc_scene_partner"]) && ($isExplicitNpcPromptEvent || $npcSceneDialogueEnabledForPrompt)) {
    $npcPromptPartnerName = $intimacyStatus["npc_scene_partner"];
} else if (!empty($intimacyStatus["npc_partner"]) && $currentEvent === "ext_nsfw_npc_invite") {
    $npcPromptPartnerName = $intimacyStatus["npc_partner"];
}

if ($npcPromptPartnerName !== "") {
    $partnerName = $npcPromptPartnerName;

    // DEFERRED: prompts.php rebuilds the NPC cues AFTER prerequest, so direct
    // replacement here is clobbered. Stash the scene partner and let prompts.php
    // resolve #PRIMARY_PARTNER# after the rebuild.
    $GLOBALS["AIAGENTNSFW_NPC_PARTNER_NAME"] = $partnerName;
    $GLOBALS["AIAGENTNSFW_SCENE_PARTNER_NAME"] = $partnerName;
    $GLOBALS["AIAGENTNSFW_NPC_INVITE_ROLE"] = trim((string)($intimacyStatus["npc_invite_role"] ?? ""));
    $GLOBALS["AIAGENTNSFW_NPC_INVITE_INITIATOR"] = trim((string)($intimacyStatus["npc_invite_initiator"] ?? ""));
    $GLOBALS["AIAGENTNSFW_NPC_INVITE_TARGET"] = trim((string)($intimacyStatus["npc_invite_target"] ?? ""));

    $speakerName = trim((string)($actorName ?? ($GLOBALS["HERIKA_NAME"] ?? "")));
    // Never pin the listener to the PLAYER: this block only forces the listener for NPC<->NPC scenes. If the resolved
    // partner is the player (e.g. an invite path stored the player as npc_partner), fall through so she addresses the
    // player normally - enum-pinning the listener to the player would FORCE exactly the bleed we're preventing.
    $rtPlayerName = trim((string)($GLOBALS["PLAYER_NAME"] ?? ""));
    if ($speakerName !== "" && strcasecmp($speakerName, $partnerName) !== 0 && ($rtPlayerName === "" || strcasecmp($partnerName, $rtPlayerName) !== 0) && !$isPlayerInputEvent) {
        // The model can still emit the player as listener/target from nearby context.
        // NPC-only scene dialogue must be delivered to the actual scene partner.
        $GLOBALS["AIAGENTNSFW_FORCE_SCENE_LISTENER"] = $partnerName;
        $GLOBALS["AIAGENTNSFW_FORCE_RECHAT_TARGET"] = $partnerName;
        // ACTUALLY CONSUME the forced listener (user directive 2026-06-29): these globals were set but never used, so
        // in an NPC-to-NPC scene the speaker addressed the PLAYER by name. Pin the response 'listener'
        // to the real scene partner via the JSON-template hook (runs after json_response rebuilds the template), so
        // she addresses her NPC partner, not the player.
        if (!isset($GLOBALS["HOOKS"]) || !is_array($GLOBALS["HOOKS"])) { $GLOBALS["HOOKS"] = []; }
        if (!isset($GLOBALS["HOOKS"]["JSON_TEMPLATE"]) || !is_array($GLOBALS["HOOKS"]["JSON_TEMPLATE"])) { $GLOBALS["HOOKS"]["JSON_TEMPLATE"] = []; }
        $GLOBALS["HOOKS"]["JSON_TEMPLATE"][] = function() use ($partnerName, $speakerName) {
            if (isset($GLOBALS["responseTemplate"]) && is_array($GLOBALS["responseTemplate"])) {
                // Bare partner name as the listener VALUE (not an instruction sentence) so the model copies it
                // verbatim into the routed 'listener' field instead of emitting the player's name.
                $GLOBALS["responseTemplate"]["listener"] = $partnerName;
            }
            // HARD constraint: pin the structured-output 'listener' to an enum of exactly the scene partner so the
            // model CANNOT emit the player as the listener in an NPC-to-NPC scene. Description reinforces it for
            // providers that don't strictly enforce enums.
            if (isset($GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]["listener"])) {
                $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]["listener"]["enum"] = [$partnerName];
                $GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]["listener"]["description"] = "Must be exactly '{$partnerName}', {$speakerName}'s partner in this NPC-to-NPC scene. Never the player.";
            }
        };
    } else if ($isPlayerInputEvent) {
        error_log("[AIAGENTNSFW] Player input during NPC scene kept player listener; scene partner context remains {$partnerName}");
    }

    error_log("[AIAGENTNSFW] Deferred NPC scene partner ({$partnerName}) for NPC-to-NPC prompt resolution");
}

// From here should apply to profiled actors

// Arousal cooldown is now GAME-TIME decay applied lazily in getIntimacyForActor (common.php) - the old
// per-conversation-turn -1 drained fastest while talking and never while sleeping/waiting, which is backwards.
if (isSexDisposalEnabled() && !isset($intimacyStatus["sex_disposal"])) {
    $intimacyStatus["sex_disposal"]=0;
    $intimacyStatus["level"]=0;
}

$actorName=$GLOBALS["HERIKA_NAME"];
// [CHIM-CORE CANDIDATE] Make the NPC acknowledge an item the player just handed it (item + value). Only let a
// player-facing conversational turn CONSUME (dedup) the gift - a silent system turn (payment-check/scene tick)
// must not eat it before she speaks, or she'll keep asking for an item she already has.
if (function_exists('aiagentNsfwInjectGiftAcknowledgment')) { aiagentNsfwInjectGiftAcknowledgment($actorName, $isPlayerInputEvent); }
// [Slave] Ask-for-freedom awareness (only when the master enabled the toggle). Explains the request->consent
// handshake so the slave knows AskForFreedom is a plea and AcceptFreedom requires the master's EXPLICIT yes.
if (function_exists('isNpcSlave') && isNpcSlave($actorName) && _getNsfwSetting("SLAVERY_ALLOW_ASK_FREEDOM", false)) {
    $slaveFreedomIntimacy = getIntimacyForActor($actorName);
    $slaveFreedomPending = !empty($slaveFreedomIntimacy["freedom_requested"]);
    $slaveFreedomPrompt = trim((string)getGlobalPrompt('slave_ask_freedom'));
    if ($slaveFreedomPrompt === '') {
        $slaveFreedomPrompt = "You are a slave owned by #PLAYER_NAME#. If you genuinely long for freedom you MAY plead for it using the AskForFreedom action - but it is only a request: #PLAYER_NAME# alone decides. Never assume you are freed.";
    }
    if ($slaveFreedomPending) {
        $slaveFreedomPrompt .= " You have already begged #PLAYER_NAME# for freedom and now await their answer. ONLY use the AcceptFreedom action if #PLAYER_NAME# has EXPLICITLY agreed, in their own words, to free you. If they refuse, ignore the plea, or have not clearly consented, do NOT free yourself - remain their slave.";
    }
    $slaveFreedomPrompt = str_replace('#PLAYER_NAME#', $GLOBALS["PLAYER_NAME"] ?? "your master", $slaveFreedomPrompt);
    $GLOBALS["HERIKA_PERSONALITY"] .= "\n<slave_freedom>\n" . $slaveFreedomPrompt . "\n</slave_freedom>";
}
$npcManager=new NpcMaster();
$npcData=$npcManager->getByName($actorName);
// Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
require_once __DIR__ . "/nsfw_data.php";
$extended_data = NsfwNpcData::get($actorName);
$metadata=$npcManager->getMetadata($npcData);

// ============================================
// AUTO-GENERATE NSFW PROFILES (QUEUED)
// ============================================
// Queue NPC for NSFW profile generation instead of fire-and-forget.
// This prevents rate limiting when entering areas with many NPCs.
// The queue is processed 1-2 at a time in context.php.
//
// Gathers FULL game context so the LLM knows if NPC is:
// - A slave (and their affinity towards owner)
// - A prostitute (from mods or occupation)
// - Married, in a relationship, etc.
// ============================================
try {
    require_once __DIR__ . '/nsfw_profile_queue.php';

    // Check if auto-generate is enabled
    $nsfwSettingsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_settings'");
    $nsfwSettings = [];
    if ($nsfwSettingsRow && !empty($nsfwSettingsRow['value'])) {
        $nsfwSettings = json_decode($nsfwSettingsRow['value'], true) ?: [];
    }

    $autoGenerateEnabled = !empty($nsfwSettings['AUTO_GENERATE_NSFW_PROFILES']);

    if ($autoGenerateEnabled && $npcData) {
        // Check if NPC already has NSFW profile - read BOTH keys (UI/auto/bg write nsfw_source, foreground queue writes source)
        $hasNsfwProfile = !empty($extended_data['source']) || !empty($extended_data['nsfw_source']);

        // Also check if we already queued/attempted generation recently (within last hour)
        $generationAttempted = isset($extended_data['nsfw_generation_attempted'])
            && (time() - $extended_data['nsfw_generation_attempted']) < 3600;

        // Check if already in queue
        $alreadyQueued = _nsfwIsInQueue($actorName);

        if (!$hasNsfwProfile && !$generationAttempted && !$alreadyQueued) {
            // Simple child/animal check inline
            $nameLower = strtolower(trim($actorName));
            $blockedNames = ['babette', 'erith', 'blaise', 'lucia', 'mila valentia', 'samuel', 'sofie', 'aventus aretino', 'braith', 'bear', 'wolf', 'dragon', 'spider', 'skeever', 'mudcrab', 'horse', 'dog', 'chicken', 'cow'];
            $isBlocked = in_array($nameLower, $blockedNames) || preg_match('/(dragon|spider|wolf|bear|troll|skeleton|ghost|draugr)$/i', $nameLower);

            if (!$isBlocked) {
                // Build FULL game context for the LLM
                $gameContext = [
                    'player_name' => $GLOBALS['PLAYER_NAME'] ?? null,
                    'current_location' => $GLOBALS['CURRENT_LOCATION'] ?? null,
                ];

                // Check if NPC is a SLAVE (from relationship system or extended data)
                $gameContext['is_slave'] = false;
                if (function_exists('isNpcSlave') && isNpcSlave($actorName)) {
                    $gameContext['is_slave'] = true;
                    $gameContext['slave_owner'] = $GLOBALS['PLAYER_NAME'] ?? 'the player';

                    // Get slave affinity from relationship system
                    if (class_exists('RelationshipManager')) {
                        try {
                            $slaveRel = RelationshipManager::getPlayerRelationship($actorName);
                            $gameContext['slave_affinity'] = $slaveRel['aff'] ?? 0;
                        } catch (Exception $e) {
                            $gameContext['slave_affinity'] = 0;
                        }
                    }
                }

                // Check if NPC is a PROSTITUTE (from mods or extended data)
                $gameContext['is_prostitute'] = false;
                $gameContext['is_courtesan'] = false;

                if (!empty($extended_data['is_prostitute']) || !empty($extended_data['profession_prostitute'])) {
                    $gameContext['is_prostitute'] = true;
                }

                // Check courtesan mods
                if (is_array($metadata["mods"] ?? null)) {
                    $prostituteMods = ["The Naked DragonSSE.esp", "prostitutes.esp"];
                    foreach ($prostituteMods as $mod) {
                        if (in_array($mod, $metadata["mods"])) {
                            $gameContext['is_courtesan'] = true;
                            $gameContext['is_prostitute'] = true;
                            break;
                        }
                    }
                    // Store mod source for context
                    $gameContext['mod_source'] = implode(', ', $metadata["mods"]);
                }

                // Get relationship with player if available
                if (class_exists('RelationshipManager')) {
                    try {
                        $playerRel = RelationshipManager::getPlayerRelationship($actorName);
                        if ($playerRel) {
                            $affinity = $playerRel['aff'] ?? 0;
                            $trust = $playerRel['trust'] ?? 50;
                            if ($affinity > 75 && $trust > 75) {
                                $gameContext['relationship_with_player'] = 'Deeply in love/devoted';
                            } else if ($affinity > 50) {
                                $gameContext['relationship_with_player'] = 'Very fond/attracted';
                            } else if ($affinity > 25) {
                                $gameContext['relationship_with_player'] = 'Friendly/warm';
                            } else if ($affinity > -25) {
                                $gameContext['relationship_with_player'] = 'Neutral';
                            } else if ($affinity > -50) {
                                $gameContext['relationship_with_player'] = 'Dislikes';
                            } else {
                                $gameContext['relationship_with_player'] = 'Hates';
                            }
                        }
                    } catch (Exception $e) {
                        // Relationship system not available
                    }
                }

                // Queue for generation (processed 1-2 at a time in context.php)
                _nsfwQueueProfileGeneration($actorName, $gameContext);
                error_log("[AIAGENT_NSFW] Queued NSFW profile generation for: {$actorName} (slave: " . ($gameContext['is_slave'] ? 'yes' : 'no') . ", prostitute: " . ($gameContext['is_prostitute'] ? 'yes' : 'no') . ")");

                // Mark that we've queued to avoid repeated queue additions
                $extended_data['nsfw_generation_attempted'] = time();
                // Save to nsfw_npc_data table (NOT core_npc_master.extended_data)
                NsfwNpcData::save($actorName, $extended_data);
            }
        }
    }
} catch (Exception $e) {
    error_log("[AIAGENT_NSFW] Auto-generate queue failed: " . $e->getMessage());
}

// Detect
$modsToCheck=[
    "The Naked DragonSSE.esp",
    "prostitutes.esp"
];

$isCourtesan=false;
if (is_array($metadata["mods"])) {
    foreach ($modsToCheck as $mod) {
        $isCourtesan=$isCourtesan||in_array($mod,$metadata["mods"]);
    }
}


// Prostitutes skip the arousal gate entirely (they gate on relationship + payment, not "in the mood") - the is_prostitute checkbox is the single source of truth
$isProstituteForBoost = isProstitute($actorName);
if ($isProstituteForBoost) {
    // No arousal lock - prostitutes bypass the arousal gate in the action-unlock logic (functions.php), so their arousal value is irrelevant
    $intimacyStatus["adult_entertainment_services_autodetected"]=true;

} else {
    $intimacyStatus["adult_entertainment_services_autodetected"]=false;
}


// Arousal from NPC data or profile.
// Only apply if sex_disposal system is enabled
if (isSexDisposalEnabled()) {
    if (isset($GLOBALS["AIAGENT_NSFW_DEFAULT_AROUSAL"]) && $GLOBALS["AIAGENT_NSFW_DEFAULT_AROUSAL"]) {    // Need npc table with tags here
        $intimacyStatus["sex_disposal"]=($intimacyStatus["sex_disposal"]<$GLOBALS["AIAGENT_NSFW_DEFAULT_AROUSAL"])?$GLOBALS["AIAGENT_NSFW_DEFAULT_AROUSAL"]: $intimacyStatus["sex_disposal"];

    }

    // Conversation-driven arousal: relax-task ambiance and flirty speech moods. GAIN COOLDOWN: at most one
    // conversational gain per AROUSAL_GAIN_COOLDOWN_SECONDS - flirty lines used to pump +2 every single turn.
    // Negative moods land immediately (never cooldown-gated). These moves are PERSISTED here: on plain
    // non-scene turns no later branch saves, so conversational arousal previously never survived the request.
    $convGain = (int)aiagentNsfwArousalNum('AROUSAL_GAIN_CONVERSATION', 2);
    $gainCooldown = (int)aiagentNsfwArousalNum('AROUSAL_GAIN_COOLDOWN_SECONDS', 60);
    $lastConvGain = (int)($intimacyStatus['arousal_conv_gain_ts'] ?? 0);
    $convGainReady = ($gainCooldown <= 0) || ((time() - $lastConvGain) >= $gainCooldown) || ($lastConvGain > time());
    $arousalMoved = false;

    $currentTask=DataGetCurrentTask();
    $wantsConvGain = (strpos($currentTask,"relax")!==false);

    // Speech mood modifier
    $moodModif=getSexDisposalFromMood($GLOBALS["HERIKA_NAME"],$GLOBALS["gameRequest"][2]);
    if ($moodModif>0.45) {
        $wantsConvGain = true;
    } else if ($moodModif<0) {
        $intimacyStatus["sex_disposal"] = max(-2, ($intimacyStatus["sex_disposal"] ?? 0) - $convGain);
        $arousalMoved = true;
    }

    if ($wantsConvGain && $convGain > 0) {
        if ($convGainReady) {
            $intimacyStatus["sex_disposal"] = ($intimacyStatus["sex_disposal"] ?? 0) + $convGain;
            $intimacyStatus['arousal_conv_gain_ts'] = time();
            $arousalMoved = true;
            error_log("[AIAGENTNSFW] Arousal conversation gain +{$convGain} -> {$intimacyStatus["sex_disposal"]} for {$GLOBALS["HERIKA_NAME"]}");
        } else {
            error_log("[AIAGENTNSFW] Arousal conversation gain SKIPPED (cooldown {$gainCooldown}s) for {$GLOBALS["HERIKA_NAME"]}");
        }
    }

    if ($arousalMoved) {
        updateIntimacyForActor($GLOBALS["HERIKA_NAME"], [
            'sex_disposal' => $intimacyStatus["sex_disposal"],
            'arousal_conv_gain_ts' => $intimacyStatus['arousal_conv_gain_ts'] ?? $lastConvGain,
        ]);
    }
}


// ============================================
// PROSTITUTE PAYMENT FLOW
// ============================================
// Prostitutes now use TakeGold function directly.
// TakeGold uses PaymentHandler to transfer gold via Papyrus
// and sets payment_confirmed=true to unlock scene progression.
// No automatic detection needed - NPC controls when to collect.
// ============================================

// ============================================
// NEW PHASE-BASED SCENE FLOW
// ============================================
// Phase: "tier_prompt" → Tier prompt fires, model accepts/refuses
// Phase: "accepted"    → Arousal check, styles inject if ready
// Phase: "engaged"     → Full scene, all styles active
//
// Level: 0 = Not in scene or tier_prompt phase
// Level: 1 = Accepted, pre-intimate (foreplay)
// Level: 2 = Engaged, full scene
// ============================================

// Determine NPC type for path selection
// PREFER stored values from scene start (nsfw_ostim_handler.php) - this ensures
// each actor gets their correct tier prompt even if they're not the first speaker
$isSlave = isset($intimacyStatus["npc_is_slave"]) ? $intimacyStatus["npc_is_slave"] : isNpcSlave($actorName);
$isProstitute = isProstitute($actorName); // checkbox is the single source of truth (ignore mod-courtesan / profession / stale cache)
$isAffair = false; // Will be set by tier prompt logic
$skoomaLevelForScene = function_exists('getDrugStageForActor') ? (int)getDrugStageForActor($actorName, 'skooma') : 0;
// L3 skooma overrides the SEX pathway even for a prostitute (she keeps her prostitute overhead/identity,
// but at L3 her sex route becomes the addict bargain - sex for skooma, independent of the payment gate).
// Slaves are a separate persistent override and auto-accept, so leave them out.
$isSkoomaAddictionBargain = (!$isSlave && ($skoomaLevelForScene >= 3 || !empty($intimacyStatus["skooma_addiction_bargain"])));
if ($isSkoomaAddictionBargain) {
    $intimacyStatus["skooma_addiction_bargain"] = true;
    if (!empty($intimacyStatus["accepted_sex"])) {
        $intimacyStatus["scene_phase"] = "accepted";
        $intimacyStatus["refusal_expressed"] = false;
        $intimacyStatus["request_scene_stop"] = false;
        $intimacyStatus["forced_scene"] = false;
        $intimacyStatus["skooma_bargain_refused"] = false;
        $scenePhase = "accepted";
    } else if (($intimacyStatus["scene_phase"] ?? null) === "rejected" && empty($intimacyStatus["skooma_bargain_refused"])) {
        $intimacyStatus["scene_phase"] = "tier_prompt";
        $intimacyStatus["refusal_expressed"] = false;
        $intimacyStatus["request_scene_stop"] = false;
        $intimacyStatus["forced_scene"] = false;
        unset($intimacyStatus["tier_prompt_sent"]);
        $scenePhase = "tier_prompt";
        error_log("[AIAGENTNSFW] Cleared stale non-bargain refusal for skooma L3 path on $actorName");
    }
    error_log("[AIAGENTNSFW] Skooma L3 addiction bargain path active for $actorName");
}

// Get affinity for slave/relationship prompts
// PREFER stored value from scene start for consistency
$slaveAffinity = 0;
$slaveOwnerName = $GLOBALS['PLAYER_NAME'] ?? 'Owner';
if ($isSlave) {
    if (isset($intimacyStatus["slave_affinity"])) {
        $slaveAffinity = $intimacyStatus["slave_affinity"];
        error_log("[AIAGENTNSFW] Using stored slave affinity for $actorName: $slaveAffinity");
    } else {
        try {
            $relationship = RelationshipManager::getPlayerRelationship($actorName);
            $slaveAffinity = $relationship['aff'] ?? 0;
            error_log("[AIAGENTNSFW] Slave affinity for $actorName: $slaveAffinity");
        } catch (Exception $e) {
            error_log("[AIAGENTNSFW] Failed to get slave affinity: " . $e->getMessage());
        }
    }
}

// Get scene phase
$scenePhase = $intimacyStatus["scene_phase"] ?? null;
$initialScenePhase = $scenePhase; // #6: true request-start phase (snapshot before the state machine mutates $scenePhase)
$GLOBALS["AIAGENTNSFW_SCENE_PHASE"] = $scenePhase;
$scenePromptContext = !empty($intimacyStatus["is_npc_scene"]) ? 'npc' : 'player';

// A defeat/assault framework explicitly identified this NPC as the aggressor and the
// player as the victim. This is not a player intimacy request, so relationship consent,
// prostitute payment, and NPC refusal machinery must not invert the aggressor's role.
$isFrameworkForcedAggressor = !empty($intimacyStatus["framework_forced_scene"])
    && !empty($intimacyStatus["scene_player_is_victim"])
    && !empty($intimacyStatus["scene_actor_is_aggressor"]);
if ($isFrameworkForcedAggressor && !$isPillowTalkMode) {
    $intimacyStatus["scene_phase"] = "engaged";
    $intimacyStatus["level"] = 2;
    $intimacyStatus["accepted_sex"] = true;
    $intimacyStatus["refusal_expressed"] = false;
    $intimacyStatus["refused_until_scene_end"] = false;
    $intimacyStatus["request_scene_stop"] = false;
    $intimacyStatus["stop_command_sent"] = false;
    $intimacyStatus["is_transaction"] = false;
    $intimacyStatus["negotiation_phase"] = false;
    $scenePhase = "engaged";
    $initialScenePhase = "engaged";
    $GLOBALS["AIAGENTNSFW_SCENE_PHASE"] = "engaged";

    $defeatPrompt = trim((string)getGlobalPrompt('defeat_aggressor_scene'));
    if ($defeatPrompt === '') {
        $defeatPrompt = 'A defeat framework identified #NPC_NAME# as an aggressor and #PLAYER_NAME# as the victim. This dedicated defeat path overrides ordinary relationship tier, romance, orientation, payment, arousal, consent, AcceptSex, and RefuseSex instructions until this scene ends. Do not ask whether #PLAYER_NAME# wants the scene, do not act disgusted by your own initiation, do not refuse your own scene, and do not claim #PLAYER_NAME# initiated it. Stay in character as the aggressor. Use the core personality of #NPC_NAME# and the current physical scene, but ignore conflicting relationship and consent prompts.';
    }
    $defeatPrompt = str_replace(
        ['#NPC_NAME#', '#PLAYER_NAME#'],
        [$actorName, $GLOBALS['PLAYER_NAME'] ?? 'the player'],
        $defeatPrompt
    );
    $GLOBALS["HERIKA_PERSONALITY"] .= "\n<defeat_aggressor_scene>\n{$defeatPrompt}\n</defeat_aggressor_scene>";
    updateIntimacyForActor($actorName, $intimacyStatus);
    error_log("[AIAGENTNSFW] Framework aggressor prompt active for {$actorName}; relationship refusal gate bypassed");
}

// REFUSAL HARD CAP (user directive 2026-06-28, ~15 min). A sticky RefuseSex normally clears at scene end, but if the
// engine scene-end signal is lost the lock could strand her in "rejected" forever. Auto-release once the cap elapses
// so she is never permanently frozen. PLAYER-NPC only (NPC-to-NPC keeps its own logic).
if (!empty($intimacyStatus["refused_until_scene_end"]) && empty($intimacyStatus["is_npc_scene"])) {
    $refusedAt = (int)($intimacyStatus["refused_until_scene_end_time"] ?? 0);
    $refusalCapSeconds = function_exists('_getNsfwSetting') ? (int)_getNsfwSetting('REFUSAL_LOCK_MAX_SECONDS', 900) : 900;
    if ($refusalCapSeconds < 60) { $refusalCapSeconds = 900; }
    // SCENE-STILL-RUNNING GUARD (fix 2026-07-01): the cap is a lost-scene-end FAILSAFE, not a mid-scene release.
    // While the engine scene is visibly active, hold the sticky refusal; the marker going stale (>600s) or the
    // ended stamp still covers the genuinely-lost case.
    $refusalSceneActiveTs = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
    $refusalSceneEndedTs  = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt") ?: 0);
    $refusalSceneRunning = $refusalSceneActiveTs > 0 && (time() - $refusalSceneActiveTs) < 600 && $refusalSceneActiveTs >= $refusalSceneEndedTs;
    if ($refusedAt > 0 && (time() - $refusedAt) >= $refusalCapSeconds && !$refusalSceneRunning) {
        $intimacyStatus["refused_until_scene_end"] = false;
        $intimacyStatus["refused_until_scene_end_time"] = null;
        $intimacyStatus["refusal_expressed"] = false;
        if (($intimacyStatus["scene_phase"] ?? '') === "rejected") {
            $intimacyStatus["scene_phase"] = null;
            $scenePhase = null;
            $GLOBALS["AIAGENTNSFW_SCENE_PHASE"] = null;
        }
        updateIntimacyForActor($actorName, $intimacyStatus);
        error_log("[AIAGENTNSFW] Refusal hard cap ({$refusalCapSeconds}s) elapsed for {$actorName} - released sticky refusal");
    }
}

$isNpcPromptRoute = !$isPillowTalkMode && (
    $isExplicitNpcPromptEvent
    || (!empty($intimacyStatus["is_npc_scene"]) && $npcSceneDialogueEnabledForPrompt)
);

if ($isNpcPromptRoute) {
    $npcGlobalPrompt = trim((string)getGlobalPrompt('npc_global_context'));
    if ($npcGlobalPrompt === '') {
        $npcGlobalPrompt = "This is an NPC-to-NPC scene. #NPC_NAME# is the speaking NPC and #PRIMARY_PARTNER# is their scene partner. The player is not the scene partner unless #PLAYER_NAME# is explicitly listed as a participant. Use this NPC's own profile, relationship state, marriage or affair context, intoxication, speech style, and unlocked kinks.";
    }
    $npcGlobalPrompt = aiagentNsfwRenderNpcScenePrompt($npcGlobalPrompt, $actorName, $intimacyStatus);
    if ($npcGlobalPrompt !== '') {
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n<npc_global_context>\n{$npcGlobalPrompt}\n</npc_global_context>";
        error_log("[AIAGENTNSFW] Injected NPC global context for {$actorName}");
    }

    $npcContextReminder = trim((string)getGlobalPrompt('npc_context_reminder'));
    if ($npcContextReminder === '') {
        $npcContextReminder = 'Stay anchored to the NPC-only scene with #PRIMARY_PARTNER#. React as #NPC_NAME# using your own speech style, current relationship context, and the current scene description. Keep it brief and in character.';
    }
    $npcContextReminder = aiagentNsfwRenderNpcScenePrompt($npcContextReminder, $actorName, $intimacyStatus);
    if ($npcContextReminder !== '') {
        $GLOBALS["AIAGENTNSFW_NPC_CONTEXT_REMINDER_TEXT"] = $npcContextReminder;
    }

    if (($scenePhase ?? null) === 'invite' || $currentEvent === 'ext_nsfw_npc_invite') {
        $npcInvitePrompt = trim((string)getGlobalPrompt('npc_invite'));
        if ($npcInvitePrompt === '') {
            $npcInvitePrompt = 'NPC invite/walk-to phase: #NPC_NAME# is #NPC_INVITE_ACTION# #PRIMARY_PARTNER# with romantic or sexual intent. This is only an invitation or approach; no sex scene has started yet. React only to the invitation, willingness, hesitation, flirtation, or refusal. Do not describe physical sex, pleasure, penetration, climax, or an active scene yet.';
        }
        $npcInvitePrompt = aiagentNsfwRenderNpcScenePrompt($npcInvitePrompt, $actorName, $intimacyStatus);
        if ($npcInvitePrompt !== '') {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n<npc_invite_context>\n{$npcInvitePrompt}\n</npc_invite_context>";
            error_log("[AIAGENTNSFW] Injected NPC invite/walk context for {$actorName}");
        }
    }

    // SLAVE / PROSTITUTE behavioral context for NPC-to-NPC scenes. The player accepted/engaged block below only
    // runs for PLAYER scenes (NPC scenes use scene_phase 'engaged' on this route), so a slave/prostitute NPC in an
    // NPC-NPC scene previously only got the tier prompt - it never carried their in-scene behavior. Wire it here so
    // they bring their own status into the scene and decide for themselves (model-driven consent). Slaves get their
    // submissive personality + speech (owner relationship is background trait). Prostitutes get their per-NPC During
    // Service Prompt behavior WITHOUT the player payment gate (there is no payment mechanic with an NPC partner).
    if ($isSlave) {
        $npcSlavePers = NsfwRelationship::getSlavePersonality($slaveAffinity, $slaveOwnerName);
        if (!empty($npcSlavePers)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('slave_personality', $npcSlavePers);
        }
        $npcSlaveSpeech = NsfwRelationship::getSlaveSpeechStyle($slaveAffinity, $slaveOwnerName);
        if (!empty($npcSlaveSpeech)) {
            $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . NsfwRelationship::wrapXml('slave_speech', $npcSlaveSpeech);
        }
        $slavePromptAlreadyInjected = true;  // mirror the player path so the universal block doesn't double-inject
        error_log("[AIAGENTNSFW] NPC-scene: injected slave behavioral context for {$actorName}");
    } else if ($isProstitute) {
        $npcDuringPrompt = function_exists('aiagentNsfwGetProstituteScenePrompt')
            ? aiagentNsfwGetProstituteScenePrompt($actorName, 'during_prompt') : '';
        if ($npcDuringPrompt !== '') {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n<prostitute_service_behavior>\n{$npcDuringPrompt}\n</prostitute_service_behavior>";
            $GLOBALS["AIAGENTNSFW_PROSTITUTE_SERVICE_BEHAVIOR"] = true; // suppress the generic speak-style block
            error_log("[AIAGENTNSFW] NPC-scene: injected prostitute service behavior for {$actorName}");
        }
    }
}

$isNpcSceneGateDisabled = !empty($intimacyStatus["is_npc_scene"]) && !$isNpcInviteRoute;
if ($isNpcSceneGateDisabled && !$isPillowTalkMode) {
    $npcGatePrompt = trim((string)getGlobalPrompt('npc_gate_disabled'));
    if ($npcGatePrompt === '') {
        $npcGatePrompt = 'This NPC-to-NPC scene is already active for routing. Do not run player-scene relationship accept/refuse logic for this NPC-to-NPC scene. Continue using personality, role, kink, intoxication, affair, relationship, speech style, and scene context normally.';
    }
    $npcGatePrompt = aiagentNsfwRenderNpcScenePrompt($npcGatePrompt, $actorName, $intimacyStatus);
    $GLOBALS["HERIKA_PERSONALITY"] .= "\n<npc_gate_disabled>\n{$npcGatePrompt}\n</npc_gate_disabled>";
    $GLOBALS["AIAGENTNSFW_NPC_GATE_DISABLED_CUE"] = "<npc_gate_disabled>\n{$npcGatePrompt}\n</npc_gate_disabled>";

    $intimacyStatus["npc_affinity_gate_disabled"] = true;
    $intimacyStatus["accepted_sex"] = true;
    $intimacyStatus["show_normal_kinks"] = true;
    $intimacyStatus["show_secret_kinks"] = true;
    $intimacyStatus["sex_started"] = true;
    $intimacyStatus["had_sex_in_scene"] = true;
    $intimacyStatus["refusal_expressed"] = false;
    $intimacyStatus["request_scene_stop"] = false;
    if ($scenePhase === null || in_array($scenePhase, ["invite", "tier_prompt", "rejected"], true)) {
        $intimacyStatus["scene_phase"] = "accepted";
        $scenePhase = "accepted";
        $GLOBALS["AIAGENTNSFW_SCENE_PHASE"] = "accepted";
    }
    error_log("[AIAGENTNSFW] NPC-to-NPC speech route active for {$actorName}; bypassing player-scene relationship/refusal gate");
}

$isUnpaidProstituteTransaction = $isProstitute
    && !$isFrameworkForcedAggressor
    && !empty($intimacyStatus["is_transaction"])
    && empty($intimacyStatus["payment_confirmed"])
    && !aiagentNsfwProstitutePaymentWaived($actorName)  // bonded/high-affinity gives it free
    && !$isSkoomaAddictionBargain;                      // L3 skooma overrides payment - she trades sex for the drug
$isCurrentSceneEvent = aiagentNsfwIsNpcSceneRuntimeEventForPrerequest($currentEvent);
$currentSceneTagsForGuard = array_map('strtolower', (array)($intimacyStatus["current_scene_tags"] ?? []));
$currentSceneTierForGuard = (int)($intimacyStatus["intensity_tier"] ?? 0);
$currentSceneNameForGuard = strtolower((string)($intimacyStatus["current_scene_name"] ?? ''));
// Raw "this pose is a standing/idle/intro pose" test.
$rawStandingPoseForGuard = $isCurrentSceneEvent
    && !$isPillowTalkMode
    && (
        $currentSceneTierForGuard <= 0
        || !empty($intimacyStatus["scene_is_idle"])
        || in_array('idle', $currentSceneTagsForGuard, true)
        || in_array('intro', $currentSceneTagsForGuard, true)
        || $currentSceneNameForGuard === 'ostim2pstandingapartmf'
    );
// Once ACTUAL SEX has started this thread, a transient standing/idle pose report must NOT yank her back to
// "standing/negotiation" - that's what blocked a paid prostitute from starting oral after payment.
$sexUnderwayForGuard = !empty($intimacyStatus["sex_started"])
    || !empty($intimacyStatus["had_sex_in_scene"])
    || !empty($intimacyStatus["service_completed"]);
// Fire the standing/intro guide only ONCE per OStim entry (scene thread). On later standing reports within
// the same thread (e.g. bouncing back from a position change) she just gets the general scene info.
$currentThreadKeyForGuard = (string)($intimacyStatus["current_scene_thread_key"] ?? '');
$standingShownThread = (string)($intimacyStatus["standing_intro_shown_thread"] ?? '');
$standingAlreadyShown = ($currentThreadKeyForGuard !== '' && $standingShownThread === $currentThreadKeyForGuard);
$isStandingIntroSceneForGuard = $rawStandingPoseForGuard && !$sexUnderwayForGuard && !$standingAlreadyShown;

if ($isStandingIntroSceneForGuard) {
    // A standing/intro/idle beat is NEVER active sex - suppress sex-content paths even for a PAID prostitute.
    $GLOBALS["AIAGENTNSFW_STANDING_ONLY"] = true;
    // Mark it shown for this thread so it fires exactly once on OStim entry, not on every return-to-standing.
    if ($currentThreadKeyForGuard !== '') {
        $intimacyStatus["standing_intro_shown_thread"] = $currentThreadKeyForGuard;
        updateIntimacyForActor($actorName, $intimacyStatus);
    }
}

if ($isUnpaidProstituteTransaction && $isCurrentSceneEvent && !$isPillowTalkMode) {
    $intimacyStatus["scene_phase"] = "tier_prompt";
    $intimacyStatus["level"] = 0;
    $intimacyStatus["sex_disposal"] = 10;
    $intimacyStatus["accepted_sex"] = false;
    $intimacyStatus["accepted_affection"] = false;
    $intimacyStatus["had_sex_in_scene"] = false;
    $intimacyStatus["sex_started"] = false;
    $intimacyStatus["forced_scene"] = false;
    $intimacyStatus["request_scene_stop"] = false;
    $intimacyStatus["stop_command_sent"] = false;
    $intimacyStatus["negotiation_phase"] = true;
    if ($isStandingIntroSceneForGuard) {
        $intimacyStatus["scene_is_idle"] = true;
        $intimacyStatus["intensity_tier"] = 0;
        $GLOBALS["AIAGENTNSFW_STANDING_ONLY"] = true;
        $GLOBALS["AIAGENTNSFW_AFFECTION_TIER"] = 0;
        unset($intimacyStatus["tier_prompt_sent"]);
    }
    updateIntimacyForActor($actorName, $intimacyStatus);
    $scenePhase = "tier_prompt";
    $initialScenePhase = "tier_prompt";
    $GLOBALS["AIAGENTNSFW_SCENE_PHASE"] = "tier_prompt";
    error_log("[AIAGENTNSFW] PROSTITUTE PAYMENT GUARD: holding {$actorName} in negotiation until TakeGold confirms payment" . ($isStandingIntroSceneForGuard ? " (standing/intro)" : ""));
}

// Anyx Edit: Inject prostitute/slave awareness into HERIKA_OCCUPATION during normal conversation.
// Without this, NPC only learns about their profession/slavery during active OStim scenes,
// making them clueless about their own services/status when player talks to them normally.
if ($scenePhase === null) {
    $occupationAdded = false;

    // --- PROSTITUTE OCCUPATION INJECTION ---
    if ($isProstitute) {
        $pricing = $extended_data['prostitute_pricing'] ?? null;
        $prostituteType = $extended_data['prostitute_type']
            ?? ($pricing['prostitute_type'] ?? null)
            ?? 'streetwalker';

        $typeDescriptions = [
            'streetwalker' => 'a street prostitute who solicits clients openly in public areas',
            'courtesan' => 'a high-class prostitute — a refined courtesan who sells companionship and sexual services to wealthy clients',
            'escort' => 'a professional prostitute who operates as a discreet escort, selling sexual services with an emphasis on privacy and quality',
            'tavern_worker' => 'a prostitute who works at a tavern, selling sexual services to patrons',
            'temple_prostitute' => 'a temple prostitute who performs sexual services as sacred rites in service to a deity',
            'camp_follower' => 'a camp prostitute who travels with armies and sells sexual services to soldiers and travelers'
        ];
        $typeDesc = $typeDescriptions[$prostituteType] ?? $typeDescriptions['streetwalker'];

        $motivation = $pricing['motivation'] ?? 'professional';
        $motivationDescriptions = [
            'professional' => 'This is your profession — business-like and experienced.',
            'desperate' => 'You need the money badly and are more willing to negotiate.',
            'luxurious' => 'You cater to wealthy clients with premium service.',
            'survival' => 'You do this to survive — pragmatic about it.',
            'enjoyment' => 'You genuinely enjoy your work.'
        ];
        $motivationDesc = $motivationDescriptions[$motivation] ?? '';

        $paymentType = $pricing['payment_type'] ?? 'gold';
        $paymentDescriptions = [
            'gold' => 'You only accept gold.',
            'favors' => 'You accept favors and services in exchange.',
            'goods' => 'You accept valuable goods and items as payment.',
            'mixed' => 'You accept gold, goods, or favors.'
        ];
        $paymentDesc = $paymentDescriptions[$paymentType] ?? 'You accept gold.';

        $occText = "\nYou offer adult entertainment services. Type: " . ucfirst(str_replace('_', ' ', $prostituteType)) . " — {$typeDesc}.";
        if (!empty($motivationDesc)) {
            $occText .= "\n{$motivationDesc}";
        }
        $occText .= "\nPayment: {$paymentDesc}";

        // Personality prompt (how NPC approaches their work)
        if ($pricing && !empty($pricing['personality_prompt'])) {
            $persPrompt = str_replace('#NPC_NAME#', $actorName, $pricing['personality_prompt']);
            $persPrompt = str_replace('#PLAYER_NAME#', $GLOBALS["PLAYER_NAME"] ?? 'client', $persPrompt);
            $occText .= "\n{$persPrompt}";
        }

        // Services and prices (price=0 means not offered)
        $actNameMap = [
            'foreplay_kissing' => 'Kissing', 'foreplay_cuddling' => 'Cuddling/Massage',
            'foreplay_groping' => 'Groping/Fondling', 'foreplay_stripping' => 'Stripping',
            'manual_handjob' => 'Handjob', 'manual_fingering' => 'Fingering',
            'manual_mutual' => 'Mutual touching',
            'oral_giving' => 'Oral (giving)', 'oral_receiving' => 'Oral (receiving)',
            'oral_mutual' => 'Mutual oral',
            'full_vaginal' => 'Vaginal sex', 'full_anal' => 'Anal sex',
            'full_both' => 'Full service',
            'solo_watch' => 'Watching solo', 'solo_masturbate' => 'Masturbation show',
            'finish_body' => 'Finish on body', 'finish_face' => 'Finish on face',
            'finish_inside' => 'Finish inside'
        ];
        $timeNameMap = [
            'time_1hr' => 'One hour', 'time_12hr' => 'Overnight (12 hrs)',
            'time_24hr' => 'Full day (24 hrs)', 'time_72hr' => 'Weekend (72 hrs)',
            'time_gfe' => 'Girlfriend Experience'
        ];
        $addonNameMap = [
            'addon_domination' => 'Domination', 'addon_submission' => 'Submission',
            'addon_watch' => 'Watch Only'
        ];
        $groupNameMap = [
            'group_threesome' => 'Threesome', 'group_foursome' => 'Foursome',
            'group_orgy' => 'Orgy'
        ];

        if ($pricing && is_array($pricing)) {
            $serviceLines = [];
            if (!empty($pricing['individual_acts'])) {
                foreach ($pricing['individual_acts'] as $act => $price) {
                    if ($price > 0) {
                        $label = $actNameMap[$act] ?? str_replace('_', ' ', $act);
                        $serviceLines[] = "  {$label}: {$price} gold";
                    }
                }
            }
            if (!empty($pricing['time_bookings'])) {
                foreach ($pricing['time_bookings'] as $dur => $price) {
                    if ($price > 0) {
                        $label = $timeNameMap[$dur] ?? str_replace('_', ' ', $dur);
                        $serviceLines[] = "  {$label} (all-inclusive): {$price} gold";
                    }
                }
            }
            if (!empty($pricing['style_addons'])) {
                foreach ($pricing['style_addons'] as $addon => $price) {
                    if ($price > 0) {
                        $label = $addonNameMap[$addon] ?? str_replace('_', ' ', $addon);
                        $serviceLines[] = "  {$label} (add-on): +{$price} gold";
                    }
                }
            }
            if (!empty($pricing['group_premiums'])) {
                foreach ($pricing['group_premiums'] as $grp => $price) {
                    if ($price > 0) {
                        $label = $groupNameMap[$grp] ?? str_replace('_', ' ', $grp);
                        $serviceLines[] = "  {$label} (surcharge): +{$price} gold";
                    }
                }
            }
            if (!empty($serviceLines)) {
                $occText .= "\nYour services and prices:\n" . implode("\n", $serviceLines);
            }
        }

        $GLOBALS["HERIKA_OCCUPATION"] = ($GLOBALS["HERIKA_OCCUPATION"] ?? '') . $occText;
        $occupationAdded = true;
        error_log("[AIAGENTNSFW] Injected prostitute occupation context for {$actorName} (type: {$prostituteType})");
    }

    // --- SLAVE OCCUPATION INJECTION ---
    if ($isSlave) {
        $ownerName = $GLOBALS['PLAYER_NAME'] ?? 'your owner';

        $occText = "\nYou are enslaved. Your owner is {$ownerName}.";

        // Fiction frame (model safety context for slavery roleplay)
        $fictionFrame = '';
        if (class_exists('NsfwRelationship') && method_exists('NsfwRelationship', 'getSlaveryFictionFrame')) {
            $fictionFrame = NsfwRelationship::getSlaveryFictionFrame();
        }
        if (!empty($fictionFrame)) {
            $occText .= "\n{$fictionFrame}";
        }

        // Per-NPC slave speak style (how slave addresses owner outside scenes)
        $slaveStyles = $extended_data['slave_speak_styles'] ?? null;
        if (is_array($slaveStyles) && !empty($slaveStyles['speak_style'])) {
            $speakStyle = str_replace('#PLAYER_NAME#', $ownerName, $slaveStyles['speak_style']);
            $occText .= "\n{$speakStyle}";
        }

        // Slave affinity context — how they feel about their owner
        if ($slaveAffinity !== 0) {
            $tierLabel = '';
            if (class_exists('RelationshipManager') && method_exists('RelationshipManager', 'getTierLabel')) {
                $tierLabel = RelationshipManager::getTierLabel($slaveAffinity);
            }
            if (!empty($tierLabel)) {
                $occText .= "\nYour feelings toward {$ownerName}: {$tierLabel} (affinity: {$slaveAffinity}).";
            }
        }

        $GLOBALS["HERIKA_OCCUPATION"] = ($GLOBALS["HERIKA_OCCUPATION"] ?? '') . $occText;
        $occupationAdded = true;
        error_log("[AIAGENTNSFW] Injected slave occupation context for {$actorName} (owner: {$ownerName}, affinity: {$slaveAffinity})");
    }

    if ($occupationAdded) {
        error_log("[AIAGENTNSFW] HERIKA_OCCUPATION now: " . substr($GLOBALS["HERIKA_OCCUPATION"] ?? '', 0, 300));
    }
}
// Anyx Edit End

// SKIP ALL SCENE PHASE HANDLING if in pillow talk mode
// Pillow talk means scene just ended - no more sex prompts!
if ($isPillowTalkMode) {
    $scenePhase = null;  // Force skip all phase handling
    error_log("[AIAGENTNSFW] Pillow talk mode - skipping all scene phase handling for $actorName");
}

// OPEN MODE DIRECT ACCEPTANCE: the permission framework is off, so an active explicit adult
// player scene must not enter the normal affinity-tier AcceptSex/RefuseSex decision machine.
// This deliberately changes state without calling the AcceptSex FUNCSERV: no arousal bonus,
// crush, payment, cooldown, or other tool side effects. Existing/sticky refusals still win.
$openModeChild = function_exists('aiagentNsfwIsProtectedNpc') ? aiagentNsfwIsProtectedNpc($actorName) : true;
$openModeSceneEvent = in_array($gameRequest[0] ?? '', [
    'chatnf_sl', 'chatnf_sl_climax', 'chatnf_sl_moan', 'chatnf_sl_naked',
    'ext_nsfw_sexcene', 'ext_nsfw_action', 'ext_nsfw_scene', 'ext_nsfw_orgasm',
    'ext_nsfw_npc_scene', 'ext_nsfw_npc_invite', 'ext_nsfw_npc_orgasm'
], true);
if (!$isPillowTalkMode
    && !(function_exists('aiagentNsfwRelDynRefuses') && aiagentNsfwRelDynRefuses($actorName))   // RelDyn's no is not auto-accepted (LOCAL hook, branch reldyn-consent)
    && function_exists('aiagentNsfwShouldAutoAcceptOpenModePlayerScene')
    && aiagentNsfwShouldAutoAcceptOpenModePlayerScene(
        $intimacyStatus,
        function_exists('aiagentNsfwOpenMode') && aiagentNsfwOpenMode(),
        $openModeChild,
        $isProstitute,
        $isSlave,
        $isSkoomaAddictionBargain,
        $openModeSceneEvent,
        $GLOBALS['PLAYER_NAME'] ?? ''
    )) {
    $openModeAffinity = function_exists('getNpcAffinity') ? (int)getNpcAffinity($actorName) : 0;
    $intimacyStatus = aiagentNsfwApplyOpenModeAcceptedState($intimacyStatus, $openModeAffinity);
    $scenePhase = 'accepted';
    $GLOBALS['AIAGENTNSFW_OPEN_MODE_AUTO_ACCEPTED'] = true;
    updateIntimacyForActor($actorName, $intimacyStatus);
    error_log("[AIAGENTNSFW] OPEN MODE: directly accepted explicit player scene for {$actorName} (affinity {$openModeAffinity}); skipped tier decision machine");
}

// ============================================
// AROUSAL GATING OFF - BYPASS ALL PHASE/LEVEL COMPLEXITY
// ============================================
// When arousal gating is OFF, skip ALL the tier_prompt/accepted/level stuff.
// Any NPC in a scene event goes straight to engaged state.
// This is the master bypass - no levels, no phases, just engaged.
// ============================================
if (!isSexDisposalEnabled() && !$isPillowTalkMode) {
    $sceneEventTypes = [
        'chatnf_sl', 'chatnf_sl_climax', 'chatnf_sl_moan', 'chatnf_sl_naked',
        'ext_nsfw_sexcene', 'ext_nsfw_action', 'ext_nsfw_scene', 'ext_nsfw_orgasm',
        'ext_nsfw_npc_scene', 'ext_nsfw_npc_invite', 'ext_nsfw_npc_orgasm'
    ];
    if (in_array($gameRequest[0] ?? '', $sceneEventTypes)) {
        // Tier prompt is the most important injection - it sets the emotional tone
        // Allow it to fire ONCE, then progress through accepted (for sex prompt injection) to engaged
        if ($scenePhase === "rejected" && !empty($intimacyStatus["accepted_sex"])) {
            // She refused earlier but has since called AcceptSex - that's the one key that unlocks the scene.
            $intimacyStatus["scene_phase"] = "accepted";
            $scenePhase = "accepted";
            error_log("[AIAGENTNSFW] AROUSAL GATING OFF - AcceptSex after refusal -> accepted for $actorName");
        } else if ($scenePhase === "rejected") {
            // NPC refused and has NOT accepted - preserve rejection/non-consent handling (refusal re-prompts)
            error_log("[AIAGENTNSFW] AROUSAL GATING OFF - preserving rejected phase for $actorName");
        } else if ($scenePhase === "affection") {
            // Affection/romantic scene - never force to engaged (stays non-sexual)
            error_log("[AIAGENTNSFW] AROUSAL GATING OFF - preserving affection phase for $actorName");
        } else if ($scenePhase === "tier_prompt" && empty($intimacyStatus["tier_prompt_sent"])) {
            // Let tier_prompt fire - don't skip to engaged yet
            error_log("[AIAGENTNSFW] AROUSAL GATING OFF - allowing tier_prompt to fire for $actorName");
        } else if ($scenePhase === "tier_prompt" && !empty($intimacyStatus["tier_prompt_sent"])) {
            // RELATIONSHIP IS THE PRIMARY GATE. Hold during idle/intro; a prostitute holds until paid (the gold);
            // a married spouse consents by type; affair + regular are affinity-gated; a slave is forced.
            $cPartner = $GLOBALS["PLAYER_NAME"] ?? "Player";
            if (is_array($intimacyStatus["scene_actors"] ?? null)) {
                foreach ($intimacyStatus["scene_actors"] as $sa) {
                    if (strtolower($sa) !== strtolower($actorName)) { $cPartner = $sa; break; }
                }
            }
            if (!empty($intimacyStatus["scene_is_idle"])) {
                error_log("[AIAGENTNSFW] AROUSAL OFF - holding tier_prompt during idle/intro for $actorName");
            } else if ($isProstitute && empty($intimacyStatus["payment_confirmed"])) {
                if ((int)($intimacyStatus["intensity_tier"] ?? 0) >= 3 && empty($intimacyStatus["scene_is_idle"])) {
                    $intimacyStatus["scene_phase"] = "rejected";
                    $intimacyStatus["accepted_sex"] = false;
                    $intimacyStatus["refusal_expressed"] = true;
                    $intimacyStatus["request_scene_stop"] = true;
                    $scenePhase = "rejected";
                    if (function_exists('aiagentNsfwSceneStopRetryDue') && aiagentNsfwSceneStopRetryDue($intimacyStatus)
                        && function_exists('aiagentNsfwQueuePlayerSceneStop')
                        && aiagentNsfwQueuePlayerSceneStop($actorName, aiagentNsfwSceneExitCommand($intimacyStatus))) {
                        if (function_exists('aiagentNsfwMarkSceneStopQueued')) {
                            aiagentNsfwMarkSceneStopQueued($intimacyStatus);
                        } else {
                            $intimacyStatus["stop_command_sent"] = true;
                            $intimacyStatus["last_scene_stop_time"] = time();
                        }
                    }
                    error_log("[AIAGENTNSFW] AROUSAL OFF - unpaid prostitute $actorName reached sex tier; rejected and queued scene stop");
                } else {
                    error_log("[AIAGENTNSFW] AROUSAL OFF - prostitute $actorName awaiting payment, holding (no consent until gold)");
                }
            } else {
                // CONSENT IS MODEL-DRIVEN AND DEFAULT-OPEN. Explicit AcceptSex / slave / paid prostitute mark
                // her accepted right here. Otherwise DO NOT reject - leave the phase at tier_prompt and defer to
                // the model-driven implicit-consent gate in PHASE 2 below (it grants consent once the scene is
                // actually sexual; no AcceptSex tool call is required). She is willing UNLESS she chooses
                // otherwise: the only "no" is the model calling RefuseSex (-> rejected), which still sticks until
                // the scene ends and then resets. No hardcoded affinity, no required AcceptSex.
                if (!empty($intimacyStatus["accepted_sex"]) || $isSlave || $isNpcSceneGateDisabled || ($isProstitute && !empty($intimacyStatus["payment_confirmed"]))) {
                    $intimacyStatus["scene_phase"] = "accepted";
                    $scenePhase = "accepted";
                    error_log("[AIAGENTNSFW] AROUSAL OFF - consent confirmed (AcceptSex/slave/paid) -> accepted for $actorName");
                } else {
                    // DEFAULT-OPEN: do NOT halt/refuse. Hold at tier_prompt and let PHASE 2's model-driven
                    // implicit-consent gate decide (no AcceptSex required). Removing the old fail-closed reject.
                    error_log("[AIAGENTNSFW] AROUSAL OFF - no explicit accept; default-open, deferring to model-driven consent for $actorName");
                }
            }
        } else if ($scenePhase === "accepted") {
            // Let accepted phase run - it injects sex prompts, then auto-transitions to engaged
            error_log("[AIAGENTNSFW] AROUSAL GATING OFF - allowing accepted phase for $actorName");
        } else {
            // Past all phases - force engaged
            $intimacyStatus["scene_phase"] = "engaged";
            $scenePhase = "engaged";
            error_log("[AIAGENTNSFW] AROUSAL GATING OFF - forcing $actorName to engaged phase");
        }
    }
}

// ============================================
// RELATIONSHIP-TYPE GATE (player-initiated, non-sex-eligible types -> warm decline, escalate if pushed)
// ============================================
// UI-configured (conf_opts 'aiagent_nsfw_reltypes'). If the NPC's relationship TYPE with the player is not
// checked sex-eligible and affinity is Friendly+ (+31), her FIRST scene beat is a tier-toned warm refusal that
// OWNS the cue (drives her line, not just background context) and marks refusal_expressed - so if the player
// pushes past it the existing two-tier refusal escalates (Tier-2 non-consent) and the scene never becomes sex.
    // Slaves/prostitutes exempt; player-only; affection (hug/kiss) scenes never gated. Acquaintance and below
    // fall through to the normal affinity tier prompts. Standing-apart/idle sex-start scenes still gate.
    // Fires only on the first decline - after that
// refusal_expressed is set, so it steps aside and the rejected handler below runs the escalation.
if ($scenePhase === "tier_prompt"
    && !$isFrameworkForcedAggressor
    && !$isSlave && !$isProstitute
    && !$isNpcSceneGateDisabled
    && !$isSkoomaAddictionBargain
    && empty($intimacyStatus["refusal_expressed"])
    && empty($intimacyStatus["accepted_sex"])) {
    require_once __DIR__ . "/nsfw_data.php";
    $rtPartner = $GLOBALS["PLAYER_NAME"] ?? "Player";
    if (is_array($intimacyStatus["scene_actors"] ?? null)) {
        foreach ($intimacyStatus["scene_actors"] as $sa) {
            if (strtolower($sa) !== strtolower($actorName)) { $rtPartner = $sa; break; }
        }
    }
    $rtRefusal = NsfwRelationship::getRelTypeGateRefusal($actorName, $rtPartner);
    if ($rtRefusal !== null) {
        aiagentNsfwSetAuthoritativeRefusalCue('relationship_boundary_instruction', $rtRefusal);
        $intimacyStatus["scene_phase"] = "rejected";
        $intimacyStatus["accepted_sex"] = false;
        $intimacyStatus["refusal_expressed"] = true;   // the warm decline IS the first refusal -> pushing escalates to Tier-2
        $rtLocked = function_exists('aiagentNsfwSceneExitLocked') && aiagentNsfwSceneExitLocked($actorName);
        $intimacyStatus["request_scene_stop"] = !$rtLocked;  // exit-locked: refusal stands, no mechanical stop
        $rtIsSceneEvent = in_array($gameRequest[0] ?? '', [
            'chatnf_sl', 'chatnf_sl_climax', 'chatnf_sl_moan', 'chatnf_sl_naked',
            'ext_nsfw_sexcene', 'ext_nsfw_action', 'ext_nsfw_scene', 'ext_nsfw_orgasm'
        ], true);
        $rtStopRetryDue = function_exists('aiagentNsfwSceneStopRetryDue') ? aiagentNsfwSceneStopRetryDue($intimacyStatus) : empty($intimacyStatus["stop_command_sent"]);
        if (!$rtLocked && $rtIsSceneEvent && $rtStopRetryDue) {
            if (function_exists('aiagentNsfwQueuePlayerSceneStop') && aiagentNsfwQueuePlayerSceneStop($actorName, aiagentNsfwSceneExitCommand($intimacyStatus))) {
                if (function_exists('aiagentNsfwMarkSceneStopQueued')) {
                    aiagentNsfwMarkSceneStopQueued($intimacyStatus);
                } else {
                    $intimacyStatus["stop_command_sent"] = true;
                }
            }
        }
        if (function_exists('aiagentNsfwMarkRefusalSpeech')) {
            aiagentNsfwMarkRefusalSpeech($intimacyStatus, "reltype|" . (string)($gameRequest[0] ?? '') . "|" . md5($actorName . "|" . $rtPartner));
        }
        updateIntimacyForActor($actorName, $intimacyStatus);
        $scenePhase = null;  // own this turn fully: skip tier prompt, accept, and the generic refusal
        error_log("[AIAGENTNSFW] REL-TYPE GATE: warm decline for $actorName (type not sex-eligible, partner $rtPartner)");
    }
}

// ============================================
// REJECTED PHASE: NPC called RefuseSex - HANDLE REFUSAL OR NON-CONSENT
// ============================================
// When an NPC refuses:
// 1. First time: inject refusal confirmation prompt, set refusal_expressed flag
// 2. If scene events continue (player forced it): inject non-consent prompt
// ============================================
if ($scenePhase === "rejected") {
    require_once __DIR__ . "/nsfw_data.php";

    // Check if this is a scene event (indicating forced continuation after refusal)
    $isForcedSceneEvent = in_array($gameRequest[0] ?? '', [
        'chatnf_sl', 'chatnf_sl_climax', 'chatnf_sl_moan', 'chatnf_sl_naked',
        'chatnf_physics',
        'ext_nsfw_sexcene', 'ext_nsfw_action', 'ext_nsfw_scene', 'ext_nsfw_orgasm'
    ]);

    // Get partner name for prompts
    $partnerName = $intimacyStatus["scene_actors"][0] ?? $GLOBALS["PLAYER_NAME"] ?? "them";
    if (is_array($intimacyStatus["scene_actors"])) {
        foreach ($intimacyStatus["scene_actors"] as $actor) {
            if (strtolower($actor) !== strtolower($actorName)) {
                $partnerName = $actor;
                break;
            }
        }
    }

    // If refusal was already expressed AND this is a scene event = the player pushed past the refusal.
    // NO TIERS, NO HARDCODED SCENE-STOP - the model decides. The scene CONTINUES (the player is forcing it);
    // mark forced_scene so the non-consent continuation prompt is re-injected every tick (player path, handled
    // below). The scene ends when the player / engine ends it, never via a server force-stop. PLAYER PATH ONLY.
    if (!empty($intimacyStatus["refusal_expressed"]) && $isForcedSceneEvent && empty($intimacyStatus["is_npc_scene"])) {
        error_log("[AIAGENTNSFW] Refused + scene continued for $actorName - non-consent continuation (model-driven, NO hard stop)");
        $intimacyStatus["scene_phase"] = "rejected";
        $intimacyStatus["forced_scene"] = true;   // scene continues as non-consensual; continuation prompt fires every tick

        $forcedScenePayload = (string)($GLOBALS["gameRequest"][3] ?? "");
        $forcedSceneName = (string)($intimacyStatus["current_scene_name"] ?? "");
        $forcedSceneDesc = (string)($intimacyStatus["current_scene_desc"] ?? $forcedScenePayload);
        $forcedSceneTags = $intimacyStatus["current_scene_tags"] ?? [];
        if (is_array($forcedSceneTags)) {
            $forcedSceneTags = implode(",", $forcedSceneTags);
        }
        $forcedSceneKey = implode("|", [
            (string)($GLOBALS["gameRequest"][0] ?? ""),
            $forcedSceneName,
            md5($forcedSceneDesc . "|" . (string)$forcedSceneTags . "|" . $forcedScenePayload)
        ]);
        $forcedRefusalDue = function_exists('aiagentNsfwRefusalSpeechDue') ? aiagentNsfwRefusalSpeechDue($intimacyStatus, $forcedSceneKey) : true;
        if (!$forcedRefusalDue) {
            updateIntimacyForActor($actorName, $intimacyStatus);
            $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
            $scenePhase = null;
            error_log("[AIAGENTNSFW] Suppressed forced-refusal speech by ticker for $actorName ($forcedSceneKey)");
            return;
        }
        $intimacyStatus["last_forced_refusal_scene_key"] = $forcedSceneKey;
        if (function_exists('aiagentNsfwMarkRefusalSpeech')) {
            aiagentNsfwMarkRefusalSpeech($intimacyStatus, $forcedSceneKey);
        }
        updateIntimacyForActor($actorName, $intimacyStatus);
        $playerRouteName = $GLOBALS["PLAYER_NAME"] ?? ($partnerName ?? 'Player');
        if (isNonConsentPromptEnabled()) {
            $tier2Prompt = NsfwData::getPrompt('non_consent');
            if (!empty($tier2Prompt)) {
                $tier2Prompt = str_replace('#PLAYER_NAME#', $playerRouteName, $tier2Prompt);
                aiagentNsfwSetAuthoritativeRefusalCue('non_consent_instruction', $tier2Prompt);
                error_log("[AIAGENTNSFW] Injected Tier-2 forced-refusal (cycle) for $actorName");
            }
        } else {
            $refusalPrompt = NsfwData::getPrompt('refusal_confirm');
            if (!empty($refusalPrompt)) {
                $refusalPrompt = str_replace('#PLAYER_NAME#', $playerRouteName, $refusalPrompt);
                aiagentNsfwSetAuthoritativeRefusalCue('refusal_instruction', $refusalPrompt);
            }
        }
        $scenePhase = null;  // skip normal scene phase handling - she NEVER engages
    } else {
        // First refusal - inject refusal confirmation prompt
        error_log("[AIAGENTNSFW] REJECTED phase for $actorName - injecting refusal confirmation prompt");

        $refusalPrompt = NsfwData::getPrompt('refusal_confirm');
        if (!empty($refusalPrompt)) {
            $refusalPrompt = str_replace('#PLAYER_NAME#', $GLOBALS["PLAYER_NAME"] ?? ($partnerName ?? 'Player'), $refusalPrompt);
            aiagentNsfwSetAuthoritativeRefusalCue('refusal_instruction', $refusalPrompt);
            error_log("[AIAGENTNSFW] Injected refusal confirmation prompt for $actorName (refused $partnerName)");
        }

        // Mark that refusal was expressed - if scene continues, it's non-consent
        $intimacyStatus["refusal_expressed"] = true;
        $reinfLocked = function_exists('aiagentNsfwSceneExitLocked') && aiagentNsfwSceneExitLocked($actorName);
        $intimacyStatus["request_scene_stop"] = !$reinfLocked;  // exit-locked: refusal stands, no mechanical stop
        $reinfStopRetryDue = function_exists('aiagentNsfwSceneStopRetryDue') ? aiagentNsfwSceneStopRetryDue($intimacyStatus) : empty($intimacyStatus["stop_command_sent"]);
        if (!$reinfLocked && $isForcedSceneEvent && $reinfStopRetryDue) {
            if (function_exists('aiagentNsfwQueuePlayerSceneStop') && aiagentNsfwQueuePlayerSceneStop($actorName, aiagentNsfwSceneExitCommand($intimacyStatus))) {
                if (function_exists('aiagentNsfwMarkSceneStopQueued')) {
                    aiagentNsfwMarkSceneStopQueued($intimacyStatus);
                } else {
                    $intimacyStatus["stop_command_sent"] = true;
                }
            }
        }
        if (function_exists('aiagentNsfwMarkRefusalSpeech')) {
            aiagentNsfwMarkRefusalSpeech($intimacyStatus, "refusal|" . (string)($gameRequest[0] ?? '') . "|" . md5($actorName . "|" . $partnerName));
        }
        updateIntimacyForActor($actorName, $intimacyStatus);

        // Don't process any scene phases - NPC refused, waiting to see if scene continues
        $scenePhase = null;  // Skip all phase handling below
    }
}

// Handle phase transitions
// Note: Check tier_prompt_sent to ensure we only inject tier prompt ONCE
// On subsequent requests, we fall through to the "accepted" block
if ($scenePhase === "affection") {
    // AFFECTION/ROMANTIC scene (tier 0/1/2): non-sexual context, bypass the entire sexual machine
    $affTier = (int)($intimacyStatus["intensity_tier"] ?? 1);
    $affDesc = $intimacyStatus["current_scene_desc"] ?? "";
    $affPartner = $GLOBALS["PLAYER_NAME"] ?? "your companion";
    if ($affTier <= 0) {
        $affTag = "standing_scene";
        $GLOBALS["AIAGENTNSFW_STANDING_ONLY"] = true;
        if ($sexUnderwayForGuard) {
            $affGuide = "You are in a standing/idle pause within the SAME ongoing intimate encounter with {$affPartner}. Sexual activity already happened in this scene. This is a breather between acts, not a new scene: preserve established consent, relationship state, and memory of what just occurred. Do not restart introductions, negotiation, or the opening acceptance/refusal decision.";
            $standingResponseInstruction = "For this reply, give exactly one short in-character breather, afterglow, closeness, or anticipation line. Do not claim this is a new encounter and do not say that nothing has happened.";
        } else if (!$isStandingIntroSceneForGuard) {
            $affGuide = "You are in a later standing/idle beat within the SAME active scene with {$affPartner}. This is not a new scene entry. Preserve the existing acceptance or refusal state and continue from it. Do not repeat introductions, payment negotiation, or the opening acceptance/refusal decision. Do not invent a new physical act unless the current scene data or player dialogue reports one.";
            $standingResponseInstruction = "For this reply, give exactly one short continuation line consistent with the existing scene state. Do not repeat the scene-entry decision.";
        } else if (!empty($isProstitute) && !empty($intimacyStatus["payment_confirmed"])) {
            // Already PAID - do NOT make her re-negotiate price. She's settled and waiting to begin.
            $affGuide = "You are in a standing/intro scene with {$affPartner}. Payment is already settled - do NOT bring up your price, fees, or gold, and do not re-negotiate. Nothing physical has happened YET, but you are paid and ready: you may START the service yourself right now - pick the act and use the matching action (MakeLove / GiveOralSex / StartAnalSex / StartBoobjob / Masturbate). Do not just wait. Do not describe the act as already happening - choose the action to begin it.";
            $standingResponseInstruction = "For this reply, give exactly one short non-explicit standing/intro line. Do not use active-sex words like thrusting, filling, deep, stroking, cock, pussy, orgasm, or climax.";
        } else if (!empty($isProstitute)) {
            $affGuide = "You are in a standing/intro scene with {$affPartner}. This is a prostitution negotiation or transaction beat, not active sex. No physical contact has happened yet: no touching, kissing, undressing, penetration, oral sex, climax, or moaning. Discuss payment, terms, willingness, boundaries, or whether the job is accepted. Do not describe sex as happening yet.";
            $standingResponseInstruction = "For this reply, give exactly one short non-explicit standing/intro line. Do not use active-sex words like thrusting, filling, deep, stroking, cock, pussy, orgasm, or climax.";
        } else {
            $affGuide = "You are in a standing/intro scene with {$affPartner}. Nothing physical has happened yet: no touching, kissing, hugging, undressing, sex, pleasure, friction, penetration, oral sex, climax, or moaning. This is not active sex. React only to presence, eye contact, waiting, deciding, boundaries, consent, intoxication, refusal, or conversation. Do not claim contact unless the current scene data or player dialogue explicitly says it happened.";
            $standingResponseInstruction = "For this reply, give exactly one short non-explicit standing/intro line. Do not use active-sex words like thrusting, filling, deep, stroking, cock, pussy, orgasm, or climax.";
        }
        $GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"] = "<standing_scene_instruction>\n{$affGuide}\n\n{$standingResponseInstruction}\n</standing_scene_instruction> " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
        $GLOBALS["AIAGENTNSFW_SCENE_PLAYER_REQUEST_OVERRIDE"] = "";
    } else {
        $affTag = ($affTier == 1) ? "affection_scene" : "romantic_scene";
        $affGuide = ($affTier == 1)
            ? "You are sharing a tender, NON-sexual moment with {$affPartner} (such as a hug, holding hands, or comfort). Respond warmly and emotionally. Do NOT sexualize this or describe explicit acts."
            : "You are sharing a romantic, intimate moment with {$affPartner} (such as a kiss, an embrace, or cuddling). Respond with warmth and romantic tension, but keep it tasteful - NOT explicit or pornographic.";
    }
    if (!empty($affDesc)) {
        // Strip the sex-scene boilerplate baked into the stored description ("This scene is for context only...
        // Express emotional excitement or distain... Do not talk about non-sexual themes during sex activity") and
        // keep ONLY the physical position sentence. Otherwise an AFFECTION beat (hug / hold hands / cuddle) inherits
        // "during sex activity" and contradicts the non-sexual guide above -> she breaks character (user report
        // 2026-06-29). The boilerplate is correct for tier-3 sex; affection beats must not carry it.
        $affDescClean = $affDesc;
        $boilerplateStart = stripos($affDescClean, 'This scene is for context only');
        if ($boilerplateStart !== false) { $affDescClean = substr($affDescClean, 0, $boilerplateStart); }
        $affDescClean = trim($affDescClean);
        if ($affDescClean !== '') { $affGuide .= "\nMoment: {$affDescClean}"; }
    }
    $GLOBALS["HERIKA_PERSONALITY"] .= "\n<{$affTag}>\n{$affGuide}\n</{$affTag}>";
    $GLOBALS["AIAGENTNSFW_AFFECTION_TIER"] = $affTier;  // prompts.php uses this to pick the non-explicit cue
    if ($affTier >= 1) {
        // Tier 1/2 affection must drive the RESPONSE cue too, not just personality. Without this, the scene turn
        // (ext_nsfw_sexcene) falls through to the DEFAULT SEXUAL commentary in prompts.php and she sexualizes a
        // hug/hold-hands ("...that sensation would drive deeper just holding hands"). user report 2026-06-29.
        $GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"] = "<{$affTag}_instruction>\n{$affGuide}\n\nFor this reply give exactly ONE short, warm, NON-explicit line. Do NOT use sexual words (no thrusting, deeper, filling, stroking, cock, pussy, climax, moaning).\n</{$affTag}_instruction> " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
    }
    error_log("[AIAGENTNSFW] affection phase (tier $affTier) for $actorName - non-sexual context injected");
} else if ($scenePhase === "tier_prompt" && empty($intimacyStatus["tier_prompt_sent"])) {
    // ============================================
    // PHASE 1: TIER PROMPT (Accept/Refuse)
    // ============================================
    // Inject ONLY the tier prompt - no styles, no scene cues
    // Model responds, and we check for acceptance on next request
    // ============================================
    error_log("[AIAGENTNSFW] Phase: tier_prompt for $actorName");

    // Inject tier prompt based on NPC type
    if ($isSlave) {
        // SLAVE: Use slave-specific tier prompt (always complies, affinity affects emotion)
        $slaveTierPrompt = NsfwRelationship::getSlaveTierPrompt($slaveAffinity, $slaveOwnerName);
        if (!empty($slaveTierPrompt)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('slave_context', $slaveTierPrompt);
            error_log("[AIAGENTNSFW] Injected SLAVE tier prompt for $actorName (affinity: $slaveAffinity)");
        }
    } else if ($isSkoomaAddictionBargain) {
        $clientName = $GLOBALS["PLAYER_NAME"] ?? "client";
        $sceneDesc = trim((string)($intimacyStatus["current_scene_desc"] ?? ""));
        $bargainContext = aiagentNsfwBuildSkoomaAddictionBargainContext($actorName, $clientName, $sceneDesc);
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $bargainContext;
        error_log("[AIAGENTNSFW] Injected SKOOMA addiction bargain prompt for $actorName");
    } else if ($isProstitute) {
        // PROSTITUTE PATH: Per flowchart, prostitutes see BOTH at tier_prompt phase:
        // 1. Affinity tier prompt (tier_prost_{tier}) - how they feel about client
        // 2. Price list with affinity-based discounts - what they charge this client
        // Then they decide: AcceptSex (proceed to negotiate) or RefuseSex (reject client)
        $clientName = $GLOBALS["PLAYER_NAME"] ?? "client";
        $allActors = $intimacyStatus["scene_actors"] ?? $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] ?? [$clientName];

        // Get affinity for price calculations
        $affinity = 0;
        try {
            $relationship = RelationshipManager::getPlayerRelationship($actorName);
            $affinity = $relationship['aff'] ?? 0;
        } catch (Exception $e) {
            // Use default 0
        }

        // 1. Get the prostitute tier prompt (tier_prost_hostile, tier_prost_friendly, etc.)
        $sceneContext = NsfwRelationship::buildSceneContext($actorName, $allActors, true, $scenePromptContext);  // true = isProstitute
        if (!empty($sceneContext)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $sceneContext;
            error_log("[AIAGENTNSFW] Injected PROSTITUTE tier prompt for $actorName (phase: tier_prompt)");
        }

        // 2. Add price list with affinity-based discounts
        $negotiationContext = buildProstituteNegotiationContext($actorName, $clientName, $affinity);
        if (!empty($negotiationContext)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $negotiationContext;
            error_log("[AIAGENTNSFW] Injected PRICE LIST with discounts for $actorName (affinity: $affinity)");
        }
    } else {
        // Regular NPC: Get actor list from stored intimacy status (persists across requests for group scenes)
        // Fall back to global if available (same-request scenario)
        $allActors = $intimacyStatus["scene_actors"] ?? $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] ?? null;

        if (is_array($allActors) && count($allActors) > 0) {
            // Build tier context (this injects the accept/refuse emotional prompt)
            $sceneContext = NsfwRelationship::buildSceneContext($actorName, $allActors, false, $scenePromptContext);

            if (!empty($sceneContext)) {
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $sceneContext;
                error_log("[AIAGENTNSFW] Injected tier prompt for $actorName (phase: tier_prompt, actors: " . implode(", ", $allActors) . ")");
                // Debug: Log the actual prompt content
                $debugContent = preg_replace('/\s+/', ' ', $sceneContext);
                error_log("[AIAGENTNSFW] Tier prompt content for $actorName: " . $debugContent);

                // EXPLICIT ACCEPTSEX NUDGE (user directive): append to the END of this tier-3 sex prompt for a regular
                // PLAYER-scene NPC whose relationship TYPE is UI-checked sex-eligible AND who is Fond+ (aff >= 56), so
                // she actually ISSUES the AcceptSex tool instead of stalling in dialogue. Player scenes only (this whole
                // branch is the player accept/refuse route; NPC-to-NPC has its own path). Never fires if she already
                // accepted or refused, or for an ineligible type / below-Fond NPC. Toggle + editable line: Prompts tab.
                if (function_exists('getGlobalPrompt') && getGlobalPrompt('acceptsex_nudge_enabled') === '1'
                    && empty($intimacyStatus["is_npc_scene"])
                    && empty($intimacyStatus["accepted_sex"]) && empty($intimacyStatus["refusal_expressed"])
                    && function_exists('aiagentNsfwRelTypeSexEligible') && aiagentNsfwRelTypeSexEligible($actorName)
                    && (function_exists('getNpcAffinity') ? (int)getNpcAffinity($actorName) : 0) >= 56) {
                    $acceptNudge = trim((string)getGlobalPrompt('acceptsex_nudge'));
                    if ($acceptNudge === '') { $acceptNudge = "##[SYSTEM] PAY ATTENTION!!! YOU MUST USE THE AcceptSex TOOL CALL NOW!!!##"; }
                    $acceptNudge = str_replace(['#NPC_NAME#', '#PLAYER_NAME#'], [$actorName, $GLOBALS['PLAYER_NAME'] ?? 'them'], $acceptNudge);
                    $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $acceptNudge;
                    error_log("[AIAGENTNSFW] AcceptSex nudge appended to tier-3 prompt for {$actorName} (eligible type + Fond+)");
                }
            }
        } else {
            error_log("[AIAGENTNSFW] WARNING: No scene actors found for tier prompt injection for $actorName");
        }

    }

    if (!$isSlave && !empty($intimacyStatus["is_npc_scene"])) {
        $npcSoftControl = aiagentNsfwRenderNpcScenePrompt(
            "This is an NPC-to-NPC scene with #PRIMARY_PARTNER#. Do not run the player accept/refuse tool path for this scene. React through #NPC_NAME#'s relationship state, speech style, personality, intoxication, kinks, and the current scene context. If the situation is genuinely unwelcome, express #NPC_NAME#'s reluctance, discomfort, or protest in dialogue. Do not call any scene-control, consent, or accept tools for this NPC-to-NPC route.",
            $actorName,
            $intimacyStatus
        );
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n<npc_scene_control>\n{$npcSoftControl}\n</npc_scene_control>";
        error_log("[AIAGENTNSFW] Injected NPC-to-NPC soft routing instruction for $actorName");
    }

    // For slaves, auto-accept (no refusal logic - slaves must comply)
    // For prostitutes and regular NPCs, model decides based on tier prompt
    if ($isSlave) {
        // Auto-progress to accepted phase
        $intimacyStatus["scene_phase"] = "accepted";
        error_log("[AIAGENTNSFW] Auto-accepting for $actorName (slave - must comply)");
    } else {
        // Regular NPC - mark that tier prompt was sent, wait for next request
        // The model's response will determine if they accepted
        // For now, we assume if scene continues (chatnf_sl), they accepted
        $intimacyStatus["tier_prompt_sent"] = true;

        // ============================================
        // CRITICAL: Use the TIER PROMPT from DB as the CUE
        // ============================================
        // The tier prompt (from UI) already contains the accept/refuse instruction
        // It should be the CUE that triggers the response, not just personality context
        // This ensures the model responds to the proposition based on UI config
        // ============================================

        // Store tier cue in global so prompts.php can use it as the CUE override.
        // We can't override PROMPTS here because prompts.php loads AFTER prerequest
        // and would overwrite our changes with the default explicit sex commentary.
        $allActors = $intimacyStatus["scene_actors"] ?? $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] ?? [$GLOBALS["PLAYER_NAME"]];
        if ($isSkoomaAddictionBargain) {
            $tierCueContext = aiagentNsfwBuildSkoomaAddictionBargainContext($actorName, $GLOBALS["PLAYER_NAME"] ?? "client", trim((string)($intimacyStatus["current_scene_desc"] ?? "")));
        } else {
            $tierCueContext = NsfwRelationship::buildSceneContext($actorName, $allActors, $isProstitute, $scenePromptContext);
        }
        if (!empty($tierCueContext) && !empty($intimacyStatus["is_npc_scene"])) {
            $tierCueContext .= "\n\n<NPC_SCENE_CONTROL>" . aiagentNsfwRenderNpcScenePrompt(
                "NPC-only route: #NPC_NAME# is with #PRIMARY_PARTNER#. Do not call player consent or accept tools for this NPC-to-NPC route. Give an in-character reaction using the tier prompt and scene context. If this is genuinely unwelcome, convey #NPC_NAME#'s reluctance or protest in dialogue.",
                $actorName,
                $intimacyStatus
            ) . "</NPC_SCENE_CONTROL>";
        }

        // DECISION-TURN CONSENT GUARD: present BOTH choices, leading with ACCEPT, so a willing/bonded NPC is not
        // nudged into refusing. The earlier version only mentioned RefuseSex, which drifted even bonded NPCs into
        // declining. AcceptSex is the default for a warm relationship; RefuseSex is the boundary path, and IF she
        // refuses the voice-guard keeps it clean. Speech-only - it does NOT decide for her or stop the scene.
        // Skips prostitutes (payment path) and NPC<->NPC routes; slaves never reach this branch (auto-accept above).
        // TIER-3 ONLY (user directive 2026-07-01): tier_prompt phase is also stamped for tier-0 standing intros;
        // composing the explicit decision directive there reads wildly out of sync. Fire only when explicit.
        if (!$isProstitute && empty($intimacyStatus["is_npc_scene"]) && (int)($intimacyStatus["intensity_tier"] ?? 0) >= 3) {
            $rvGuard = trim((string)NsfwData::getPrompt('refusal_voice_guard'));
            if ($rvGuard === '') {
                $rvGuard = "Set aside any flirtatious, seductive, teasing, or playful manner - a refusal is a real boundary, not part of the scene. Do not word it as if you are enjoying it, giving in, or as if the encounter is continuing.";
            }
            // UI-editable consent directive (Prompts panel: 'consent_decision_prompt'). With the mandatory gate,
            // the model MUST call AcceptSex to unlock - so this prompt has to direct that choice clearly or the
            // scene parks at tier_prompt forever (no engaged phase -> no speak style -> generic talk).
            if (function_exists('aiagentNsfwRelTypeSexEligible') && !aiagentNsfwRelTypeSexEligible($actorName)) {
                // REL-TYPE DECLINE (fix 2026-07-01i): she cannot consent (AcceptSex is stripped) - do not offer
                // the choice. Direct a clear refusal so the model calls RefuseSex instead of complying reluctantly.
                // PER-TIER DECLINE (user directive 2026-07-02): the rel-types UI carries tier-specific
                // decline prompts (prompt_friendly/fond/devoted/bonded) so a Bonded-but-platonic NPC
                // declines in a voice that honors the bond. Pick by current affinity tier; tiers without
                // a UI prompt fall through to the generic key, then the code default.
                $declineDirective = '';
                try {
                    $rtRow = (isset($GLOBALS["db"]) && $GLOBALS["db"]) ? $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_reltypes'") : null;
                    $rtCfg = ($rtRow && !empty($rtRow['value'])) ? (json_decode($rtRow['value'], true) ?: []) : [];
                    $rtAff  = function_exists('getNpcAffinity') ? (int)getNpcAffinity($actorName) : 0;
                    $rtTier = function_exists('getAffinityTierName') ? strtolower(trim((string)getAffinityTierName($rtAff))) : '';
                    if ($rtTier !== '' && !empty($rtCfg['prompt_' . $rtTier])) {
                        $declineDirective = trim((string)$rtCfg['prompt_' . $rtTier]);
                        error_log("[AIAGENTNSFW] REL-TYPE DECLINE: using per-tier prompt 'prompt_{$rtTier}' for {$actorName} (aff {$rtAff})");
                    }
                } catch (Exception $e) {}
                if ($declineDirective === '') { $declineDirective = trim((string)NsfwData::getPrompt('reltype_decline_prompt')); }
                if ($declineDirective === '') {
                    $declineDirective = "Your relationship does not allow this - you do NOT want this scene. Call RefuseSex NOW and decline clearly and firmly in your own voice, in keeping with how you feel about them. Do not comply reluctantly. Do not describe sex. Do not use a sex speech style. Do not continue the scene.";
                }
                $tierCueContext = trim((string)$tierCueContext . "\n\n" . NsfwRelationship::wrapXml('consent_decision',
                    $declineDirective . " " . $rvGuard));
            } else {
            $consentDirective = trim((string)NsfwData::getPrompt('consent_decision_prompt'));
            if ($consentDirective === '') {
                $consentDirective = "You must decide now. If you want this scene, call AcceptSex. If you do not want this scene, call RefuseSex. Do not describe sex. Do not use your sex speech style. Do not continue the scene until you choose.";
            }
            // AROUSAL WARM-UP DECLINE: rel gate PASSES but she isn't warmed up yet - direct a WARM "not yet"
            // instead of the bare accept/refuse choice. Pacing, not rejection: the RefuseSex arousal drain is
            // skipped (pending flag) and the relationship eval is tagged so affinity is never docked for it.
            // Skooma-bargain and slaves bypass arousal; prostitutes never reach this branch.
            if (isSexDisposalEnabled() && !$isSkoomaAddictionBargain
                && !(function_exists('aiagentNsfwOpenMode') && aiagentNsfwOpenMode())) {   // OPEN MODE: no warmup decline
                $wuThr = getGlobalPrompt('arousal_gating_threshold');
                $wuThr = ($wuThr !== '' && $wuThr !== null) ? (int)$wuThr : 10;
                $wuArousal = (int)($intimacyStatus["sex_disposal"] ?? 0);
                if ($wuArousal < $wuThr) {
                    $wuPrompt = trim((string)getGlobalPrompt('arousal_warmup_decline'));
                    if ($wuPrompt === '') {
                        $wuPrompt = "You like #PLAYER_NAME# and this is wanted - but your body is not there yet (arousal #AROUSAL#). Decline THIS advance warmly: no cold rejection, no offense taken. Tell them what would get you in the mood - closeness, kisses, slow hands, sweet words - and invite them to warm you up. If you formally decline the scene, call RefuseSex, but keep your words affectionate and full of promise. This is pacing, not rejection - never treat #PLAYER_NAME# as unwelcome.";
                    }
                    $wuPrompt = str_replace(['#PLAYER_NAME#', '#NPC_NAME#', '#AROUSAL#'],
                        [$GLOBALS['PLAYER_NAME'] ?? 'the player', $actorName, $wuArousal], $wuPrompt);
                    $consentDirective = $wuPrompt;
                    $GLOBALS['AIAGENTNSFW_AROUSAL_PACING_DECLINE'] = true;
                    $intimacyStatus['arousal_pacing_decline_pending'] = true;
                    error_log("[AIAGENTNSFW] AROUSAL WARMUP DECLINE injected for {$actorName} (arousal {$wuArousal} < {$wuThr}, rel gate passed)");
                }
            }
            $tierCueContext = trim((string)$tierCueContext . "\n\n" . NsfwRelationship::wrapXml('consent_decision',
                $consentDirective . " If you refuse, " . $rvGuard));
            }
        }

        if (!empty($tierCueContext)) {
            $GLOBALS["AIAGENTNSFW_TIER_CUE_OVERRIDE"] = $tierCueContext;
            error_log("[AIAGENTNSFW] Stored tier prompt CUE override for prompts.php to pick up");
        }
    }

    $tierContextAlreadyInjected = true;  // Prevent re-injection in universal block

} else if ($scenePhase === "accepted" || ($scenePhase === "tier_prompt" && !empty($intimacyStatus["tier_prompt_sent"]))) {
    // ============================================
    // PHASE 2: ACCEPTED - Set Level 1, Inject Styles
    // ============================================
    // Model accepted. Set level 1 and inject personality/styles.
    // Then check arousal to determine if we progress to level 2.
    // ============================================

    // If we're still in tier_prompt but prompt was sent, the scene continuing means accept
    if ($scenePhase === "tier_prompt") {
        // Consent is MODEL-DRIVEN. Explicit AcceptSex / slave / paid still count, AND a romanticized (Fond+) NPC
        // OR a scene that has actually escalated to sex (and was NOT refused) counts as IMPLICIT consent - the scene
        // proceeding IS consent. This is what fixes bonded NPCs getting force-refused + scene-stopped just because
        // the model never called AcceptSex (the scene held at tier_prompt, escalated to sex unaccepted, then the
        // consent gate hard-stopped it). The model can still RefuseSex to get out - that now sticks. Prostitutes are
        // excluded here (their payment gate is enforced below); actual refusals (refused_until_scene_end / rejected)
        // are excluded so a real "no" still holds.
        $consentAffinity = function_exists('getNpcAffinity') ? (int)getNpcAffinity($actorName) : 0;
        $sceneReachedSex = ((int)($intimacyStatus["intensity_tier"] ?? 0) >= 3) && empty($intimacyStatus["scene_is_idle"]);
        // Implicit consent requires the scene to have ACTUALLY reached sex (tier>=3, not idle). Being romanticized
        // alone must NOT auto-engage - otherwise a bonded NPC just standing in a tier-0 scene gets marked accepted
        // and assumes sex is happening. Romanticized NPCs still don't need an explicit AcceptSex once sex starts.
        // MANDATORY SOFT GATE: consent must be EXPLICITLY chosen by the model. The old implicit "scene reached
        // sex -> assume yes" is REMOVED - it let the player blow straight past a refusal and flooded her with
        // scene prompts she never agreed to. She unlocks ONLY via explicit AcceptSex (or slave / paid prostitute /
        // gate-disabled NPC route). Otherwise the scene HOLDS at tier_prompt below until she calls AcceptSex or
        // RefuseSex - nothing else advances. RefuseSex still sticks until the scene ends.
        // Explicit AcceptSex / slave / paid prostitute unlock immediately. PLUS implicit consent once the scene has
        // ACTUALLY reached sex AND she has NOT refused (refused_until_scene_end / rejected). A weak model often will
        // not emit AcceptSex, which left every real scene "unaccepted" - so she reached orgasm still flagged as
        // non-consensual and cried that she never consented. Implicit consent fixes that; RefuseSex still overrides
        // (sticky), and an ineligible-type NPC is told to refuse by the gate before this ever applies.
        // STRICT EXPLICIT CONSENT (user directive 2026-06-28): the model MUST decide. Implicit "the scene
        // reached sex -> assume yes" is REMOVED entirely. Reaching tier 3 without an explicit AcceptSex no
        // longer grants consent - the scene HOLDS at the decision gate (below) and, crucially, the all-important
        // speak style is withheld for the rest of the scene (a model that never clears the gate gets a flat,
        // styleless "bad orgasm" - the intended consequence). The consent bark fires a fast decision turn so a
        // willing NPC clears it immediately instead of getting stuck. Only EXPLICIT AcceptSex / slave / paid
        // prostitute / gate-disabled NPC unlock.
        $modelDrivenConsent = false;
        if (!empty($intimacyStatus["accepted_sex"]) || $isSlave || $isNpcSceneGateDisabled || ($isProstitute && !empty($intimacyStatus["payment_confirmed"])) || $modelDrivenConsent) {
            $intimacyStatus["scene_phase"] = "accepted";
            $intimacyStatus["accepted_sex"] = true;  // record consent so downstream + orgasm handlers don't force-refuse
            // KINK UNLOCK (was done by the removed AcceptSex FUNCSERV): affinity-gated kinks must still enable on
            // consent or speech styles + kinks stay blocked. Fond+ (>=56) normal, Devoted+ (>=76) secret. Prostitutes
            // reveal no personal kinks (business only), so leave theirs alone.
            if (!$isProstitute) {
                $intimacyStatus["show_normal_kinks"] = ($consentAffinity >= 56);
                $intimacyStatus["show_secret_kinks"] = ($consentAffinity >= 76);
            }
            $scenePhase = "accepted";
            error_log("[AIAGENTNSFW] Consent present (explicit or model-driven implicit) -> accepted for $actorName (aff={$consentAffinity}, reachedSex=" . ($sceneReachedSex ? 'Y' : 'N') . ", kinks N=" . (($consentAffinity>=56)?'Y':'N') . "/S=" . (($consentAffinity>=76)?'Y':'N') . ")");
	        } else {
	            // stay at tier_prompt - do NOT advance, do NOT grant consent. Nothing but the model accepts.
	            error_log("[AIAGENTNSFW] tier_prompt HELD - waiting on model AcceptSex/RefuseSex (no implicit consent) for $actorName");
	            $allActors = $intimacyStatus["scene_actors"] ?? $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] ?? [$GLOBALS["PLAYER_NAME"]];
	            if ($isSkoomaAddictionBargain) {
	                $heldTierCue = aiagentNsfwBuildSkoomaAddictionBargainContext($actorName, $GLOBALS["PLAYER_NAME"] ?? "client", trim((string)($intimacyStatus["current_scene_desc"] ?? "")));
	            } else {
	                $heldTierCue = NsfwRelationship::buildSceneContext($actorName, $allActors, $isProstitute, $scenePromptContext);
	            }
	            $heldSceneDesc = trim((string)($intimacyStatus["current_scene_desc"] ?? ""));
	            $heldIsUnpaidProstitute = $isProstitute && empty($intimacyStatus["payment_confirmed"]);
	            if ($heldIsUnpaidProstitute && (int)($intimacyStatus["intensity_tier"] ?? 0) >= 3 && empty($intimacyStatus["scene_is_idle"])) {
	                $intimacyStatus["scene_phase"] = "rejected";
	                $intimacyStatus["accepted_sex"] = false;
	                $intimacyStatus["refusal_expressed"] = true;
	                $intimacyStatus["request_scene_stop"] = true;
	                $scenePhase = "rejected";
	                if (function_exists('aiagentNsfwSceneStopRetryDue') && aiagentNsfwSceneStopRetryDue($intimacyStatus)
	                    && function_exists('aiagentNsfwQueuePlayerSceneStop')
	                    && aiagentNsfwQueuePlayerSceneStop($actorName, aiagentNsfwSceneExitCommand($intimacyStatus))) {
	                    if (function_exists('aiagentNsfwMarkSceneStopQueued')) {
	                        aiagentNsfwMarkSceneStopQueued($intimacyStatus);
	                    } else {
	                        $intimacyStatus["stop_command_sent"] = true;
	                        $intimacyStatus["last_scene_stop_time"] = time();
	                    }
	                }
	                $heldTierCue .= "\n\n<payment_gate_context>\nPayment has not been confirmed by the TakeGold tool, but OStim stage data is already at sex tier. This is a nonpayment boundary problem. Refuse because payment was not confirmed; the server has queued the scene to stop. Do not speak as if paid sex is underway. Do not moan or express pleasure.\n</payment_gate_context>";
	                error_log("[AIAGENTNSFW] tier_prompt HELD - unpaid prostitute $actorName reached sex tier; rejected and queued scene stop");
	            } else if ($heldSceneDesc !== "" && !$heldIsUnpaidProstitute) {
	                $heldTierCue .= "\n\n<current_ostim_scene>\n{$heldSceneDesc}\n</current_ostim_scene>";
	            } else if ($heldIsUnpaidProstitute) {
	                $heldTierCue .= "\n\n<payment_gate_context>\nYou are still in a prostitute transaction and payment has not been confirmed by the TakeGold tool. If OStim stage data has advanced into sex anyway, understand this as a nonpayment boundary problem: refuse because payment was not confirmed and choose RefuseSex so the scene starts exiting. Do not speak as if paid sex is underway.\n</payment_gate_context>";
	            }
			            // Consent is model-driven: NO hardcoded consent cue. The UI tier prompt + RefuseSex tool suffice.
	            $GLOBALS["AIAGENTNSFW_TIER_CUE_OVERRIDE"] = $heldTierCue;
	            error_log("[AIAGENTNSFW] Re-applied tier prompt CUE override while held at tier_prompt for prompts.php");
	        }
	    }

    if ($scenePhase !== "accepted") {
        updateIntimacyForActor($actorName, $intimacyStatus);
        $tierContextAlreadyInjected = true;
    } else {

    // ACCEPT sets level = 1 (pre-intimate) - ONLY when arousal gating is ON
    if (isSexDisposalEnabled()) {
        $intimacyStatus["level"] = 1;
        error_log("[AIAGENTNSFW] Phase: accepted for $actorName, level set to 1");
    } else {
        error_log("[AIAGENTNSFW] Phase: accepted for $actorName (arousal gating OFF - no level set)");
    }

    // ============================================
    // INJECT PERSONALITY/STYLE at Level 1
    // ============================================
    // Styles inject AFTER accept but BEFORE arousal check
    // ============================================
    error_log("[AIAGENTNSFW] Injecting styles at level 1 for $actorName");

    // ============================================
    // ACCEPTED PHASE: Wait for actual sex to start
    // ============================================
    // At ACCEPTED, we only inject basic personality context
    // Sex prompts, speech styles, profanity, and KINKS inject
    // when sex_started == true (actual sex stage, not idle)
    // WHEN AROUSAL GATING IS OFF: bypass sex_started check - inject immediately
    // ============================================
    $sexStarted = !empty($intimacyStatus["sex_started"]) || !isSexDisposalEnabled();

    // Inject personality and speech style based on NPC type
    if ($isSlave) {
        // SLAVE PATH ONLY: Slave-specific personality and speech
        $slavePersonality = NsfwRelationship::getSlavePersonality($slaveAffinity, $slaveOwnerName);
        if (!empty($slavePersonality)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('slave_personality', $slavePersonality);
            error_log("[AIAGENTNSFW] Injected SLAVE personality for $actorName (affinity: $slaveAffinity)");
        }
        $slavePromptAlreadyInjected = true;  // Prevent duplicate injection in universal block

        // Slave speech style and scene cues only when sex ACTUALLY starts
        if ($sexStarted) {
            $slaveSpeech = NsfwRelationship::getSlaveSpeechStyle($slaveAffinity, $slaveOwnerName);
            if (!empty($slaveSpeech)) {
                $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . NsfwRelationship::wrapXml('slave_speech', $slaveSpeech);
                error_log("[AIAGENTNSFW] Injected SLAVE speech style for $actorName (sex started)");
            }
        } else {
            error_log("[AIAGENTNSFW] SLAVE $actorName accepted - waiting for actual sex to start");
        }
    } else if ($isProstitute) {
        // PROSTITUTE PATH ONLY: Negotiation context if payment not confirmed
        if (empty($intimacyStatus["payment_confirmed"])) {
            $clientName = $GLOBALS["PLAYER_NAME"] ?? "client";
            $affinity = 0;
            try {
                $relationship = RelationshipManager::getPlayerRelationship($actorName);
                $affinity = $relationship['aff'] ?? 0;
            } catch (Exception $e) {
                // Use default 0
            }
            $negotiationContext = buildProstituteNegotiationContext($actorName, $clientName, $affinity);
            if (!empty($negotiationContext)) {
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $negotiationContext;
                error_log("[AIAGENTNSFW] Injected NEGOTIATION context with price list for $actorName (accepted phase)");
            }
        } else if ($sexStarted && !$isStandingIntroSceneForGuard) {
            // Payment confirmed AND real sex underway (NOT a tier-0 idle/standing/intro beat) - inject sex prompts.
            // Without the standing/intro guard, a PAID prostitute standing in front of the player floods with
            // sex/slutty speech because $sexStarted is forced true when sex-disposal is disabled.
            $paidAmount = $intimacyStatus["payment_confirmed_amount"] ?? null;
            $paidService = trim((string)($intimacyStatus["payment_service"] ?? "the agreed service"));
            $paidLine = $paidAmount ? "{$GLOBALS["PLAYER_NAME"]} already paid {$paidAmount} gold for {$paidService}." : "{$GLOBALS["PLAYER_NAME"]} already paid for {$paidService}.";
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n<prostitute_payment_status>\n{$paidLine} Do not ask for payment again during this service. Stay professional and proceed with the paid arrangement.\n</prostitute_payment_status>";
            // A prostitute uses HER OWN per-NPC "During Service Prompt" (set under the is_prostitute
            // checkbox) as her in-scene behavior - NOT the generic sex_speech_style. Only fall back to the
            // generic speak style if she has no During Service Prompt configured.
            $duringPrompt = function_exists('aiagentNsfwGetProstituteScenePrompt')
                ? aiagentNsfwGetProstituteScenePrompt($actorName, 'during_prompt') : '';
            if ($duringPrompt !== '') {
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n<prostitute_service_behavior>\n{$duringPrompt}\n</prostitute_service_behavior>";
                $GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"] = "<prostitute_service_instruction>\n{$duringPrompt}\n</prostitute_service_instruction> " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
                $GLOBALS["AIAGENTNSFW_PROSTITUTE_SERVICE_BEHAVIOR"] = true; // suppress the generic speak-style block
                error_log("[AIAGENTNSFW] Prostitute $actorName using per-NPC During Service Prompt (generic speak style suppressed)");
            } else {
                error_log("[AIAGENTNSFW] Prostitute payment confirmed + sex started - no During Service Prompt set, using generic sex prompts for $actorName");
                setSexPrompt($GLOBALS["HERIKA_NAME"]);
                setSexSpeechStyle($GLOBALS["HERIKA_NAME"]);
            }
        } else {
            $paidAmount = $intimacyStatus["payment_confirmed_amount"] ?? null;
            $paidService = trim((string)($intimacyStatus["payment_service"] ?? "the agreed service"));
            $paidLine = $paidAmount ? "{$GLOBALS["PLAYER_NAME"]} already paid {$paidAmount} gold for {$paidService}." : "{$GLOBALS["PLAYER_NAME"]} already paid for {$paidService}.";
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n<prostitute_payment_status>\n{$paidLine} Do not ask for payment again. You are waiting for the paid service to actually begin.\n</prostitute_payment_status>";
            error_log("[AIAGENTNSFW] PROSTITUTE $actorName payment confirmed - waiting for actual sex to start");
        }
    } else {
        // REGULAR NPC / MARRIAGE / AFFAIR PATH
        // Sex prompts, speech style, profanity, and KINKS only when sex ACTUALLY starts
        if ($sexStarted) {
            // Check if NPC has profile (for profile vs default prompts)
            require_once __DIR__ . "/nsfw_data.php";
            $npcExtended = NsfwNpcData::get($actorName);
            $hasProfile = !empty($npcExtended['source']) || !empty($npcExtended['nsfw_source']); // read both keys

            if ($hasProfile) {
                error_log("[AIAGENTNSFW] NPC $actorName HAS PROFILE - using profile prompts");
            } else {
                error_log("[AIAGENTNSFW] NPC $actorName NO PROFILE - using default prompts");
            }

            // Inject sex prompt, speech style, profanity, and kinks (affinity-gated)
            setSexPrompt($GLOBALS["HERIKA_NAME"]);
            setSexSpeechStyle($GLOBALS["HERIKA_NAME"]);
            error_log("[AIAGENTNSFW] Injected sex prompts for $actorName (sex started)");
        } else {
            error_log("[AIAGENTNSFW] Regular NPC $actorName accepted - waiting for actual sex to start");
        }
    }

    // ============================================
    // AROUSAL CHECK - determines level 1 vs 2
    // ============================================
    // ONLY runs when arousal gating is ON
    // When OFF, skip straight to engaged
    // ============================================
    if (isSexDisposalEnabled()) {
        // AROUSAL GATING ON - check thresholds (honor the admin-configured threshold, matching the soft-decline gate)
        $thr = getGlobalPrompt('arousal_gating_threshold');
        $arousalThreshold = ($thr !== '' && $thr !== null) ? (int)$thr : 10;
        $currentArousal = $intimacyStatus["sex_disposal"] ?? 0;

        if ($isProstitute) {
            // PROSTITUTE: Payment confirmation is REQUIRED before engaging
            if (!empty($intimacyStatus["payment_confirmed"])) {
                $intimacyStatus["scene_phase"] = "engaged";
                $intimacyStatus["level"] = 2;
                error_log("[AIAGENTNSFW] Prostitute payment confirmed - engaging scene for $actorName");
            } else {
                $intimacyStatus["level"] = 0;
                error_log("[AIAGENTNSFW] Prostitute awaiting payment - staying at level 0 for $actorName");
            }
        } else if ($isSkoomaAddictionBargain) {
            $intimacyStatus["scene_phase"] = "engaged";
            $intimacyStatus["level"] = 2;
            error_log("[AIAGENTNSFW] Skooma addiction bargain accepted - bypassing arousal gate for $actorName");
        } else if ($isSlave || $currentArousal >= $arousalThreshold
                   || (function_exists('aiagentNsfwOpenMode') && aiagentNsfwOpenMode())) {   // OPEN MODE: arousal never blocks (prostitute payment branch above is untouched)
            $intimacyStatus["scene_phase"] = "engaged";
            $intimacyStatus["level"] = 2;
            error_log("[AIAGENTNSFW] Arousal check passed ($currentArousal >= $arousalThreshold) - engaging scene for $actorName");
        } else {
            $arousalLowPrompt = getGlobalPrompt('arousal_low');
            if (!empty($arousalLowPrompt)) {
                $arousalPlayerName = $GLOBALS["PLAYER_NAME"] ?? "the player";
                $arousalLowPrompt = str_replace(['#PLAYER_NAME#', '#AROUSAL#'], [$arousalPlayerName, $currentArousal], $arousalLowPrompt);
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n<arousal_gate>\n{$arousalLowPrompt}\n</arousal_gate>";
                // Any refusal born of this low-arousal hold is pacing, not dislike - protect the rel eval.
                $GLOBALS['AIAGENTNSFW_AROUSAL_PACING_DECLINE'] = true;
                error_log("[AIAGENTNSFW] Injected arousal_low AFTER consent ($currentArousal < threshold $arousalThreshold) for $actorName");
            }
            error_log("[AIAGENTNSFW] Arousal too low ($currentArousal < $arousalThreshold) - foreplay for $actorName");
        }
    } else {
        // AROUSAL GATING OFF - go straight to engaged, no checks
        $intimacyStatus["scene_phase"] = "engaged";
        $intimacyStatus["level"] = 2;
        error_log("[AIAGENTNSFW] Arousal gating OFF - engaging scene for $actorName (no arousal checks)");
    }
    }
}

// ============================================
// LEVEL MANAGEMENT - ONLY WHEN AROUSAL GATING IS ON
// ============================================
// Levels are part of the sex_disposal/arousal system.
// When arousal gating is OFF, skip ALL level manipulation.
// ============================================
if (isSexDisposalEnabled()) {
    // Handle chatnf_sl event (scene is actively running)
    if ($gameRequest[0] == "chatnf_sl") {
        // If we're in tier_prompt phase and scene is running, do not infer consent.
        if ($scenePhase === "tier_prompt") {
            if (!empty($intimacyStatus["accepted_sex"]) || $isSlave || $isNpcSceneGateDisabled || ($isProstitute && !empty($intimacyStatus["payment_confirmed"]))) {
                $intimacyStatus["scene_phase"] = "accepted";
                error_log("[AIAGENTNSFW] chatnf_sl during tier_prompt - real consent present, accepting");
            } else {
                error_log("[AIAGENTNSFW] chatnf_sl during tier_prompt - holding for model AcceptSex/RefuseSex");
            }
        }

        // Ensure we're at least level 1 only after the relationship/model gate is passed
        if ($intimacyStatus["level"] < 1 && in_array($intimacyStatus["scene_phase"] ?? null, ["accepted", "engaged"], true)) {
            $intimacyStatus["level"] = 1;
        }

        // If phase is engaged, ensure level 2
        if ($intimacyStatus["scene_phase"] === "engaged") {
            $intimacyStatus["level"] = 2;
        }
    }

    // ============================================
    // SLAVE FIX: Slaves skip acceptance phase - they just comply
    // But ONLY if there's actually a scene in progress (level >= 1 or scene_actors present)
    // AND we're in an actual scene event - not regular dialogue after rejection
    // ============================================
    $sceneActorsForSlaveCheck = $intimacyStatus["scene_actors"] ?? [];
    $isSceneEventForSlaveCheck = in_array($gameRequest[0] ?? '', [
        'chatnf_sl', 'chatnf_sl_climax', 'chatnf_sl_moan', 'chatnf_sl_naked',
        'ext_nsfw_sexcene', 'ext_nsfw_action', 'ext_nsfw_scene', 'ext_nsfw_orgasm',
        'ext_nsfw_npc_scene', 'ext_nsfw_npc_invite', 'ext_nsfw_npc_orgasm'
    ]);
    $sceneInProgress = $isSceneEventForSlaveCheck && (
        ($intimacyStatus["level"] >= 1) || (!empty($sceneActorsForSlaveCheck) && count($sceneActorsForSlaveCheck) > 0)
    );

    if ($isSlave && $sceneInProgress && $intimacyStatus["level"] < 2) {
        $intimacyStatus["level"] = 2;
        $intimacyStatus["scene_phase"] = "engaged";
        error_log("[AIAGENTNSFW] Slave in active scene - forcing $actorName to level 2 (slaves always comply)");
    }
}

// ============================================
// GROUP SCENE FIX: If scene_actors exists and has entries,
// the scene is active - force level to at least 1
// This ensures ALL participants get prompts once anyone accepts
// BUT ONLY if we're in an active scene event (chatnf_sl, ext_nsfw_sexcene, etc)
// NOT on regular dialogue - that means the scene was rejected/cancelled
// ONLY applies when arousal gating is ON (levels are part of that system)
// ============================================
$sceneActors = $intimacyStatus["scene_actors"] ?? [];
$isSceneEvent = in_array($gameRequest[0] ?? '', [
    'chatnf_sl', 'chatnf_sl_climax', 'chatnf_sl_moan', 'chatnf_sl_naked',
    'ext_nsfw_sexcene', 'ext_nsfw_action', 'ext_nsfw_scene', 'ext_nsfw_orgasm',
    'ext_nsfw_npc_scene', 'ext_nsfw_npc_invite', 'ext_nsfw_npc_orgasm'
]);

// Group scene level management - ONLY when arousal gating is ON
if (isSexDisposalEnabled()) {
    if (!empty($sceneActors) && count($sceneActors) > 1 && $intimacyStatus["level"] < 1 && $isSceneEvent) {
        // Group scenes still require the same real consent gate per actor.
        $tierPromptSent = !empty($intimacyStatus["tier_prompt_sent"]);
        $groupConsent = !empty($intimacyStatus["accepted_sex"]) || $isSlave || $isNpcSceneGateDisabled || ($isProstitute && !empty($intimacyStatus["payment_confirmed"]));

        if ($groupConsent || $intimacyStatus["scene_phase"] === "accepted") {
            // Explicitly accepted (or special class path) - safe to proceed
            $intimacyStatus["level"] = 1;
            if (empty($intimacyStatus["scene_phase"]) || $intimacyStatus["scene_phase"] === "tier_prompt") {
                $intimacyStatus["scene_phase"] = "accepted";
            }
            error_log("[AIAGENTNSFW] Group scene active - forcing $actorName to level 1 (scene has " . count($sceneActors) . " actors, real consent present)");
        } else {
            // No consent yet - let them go through accept/refuse phase first
            error_log("[AIAGENTNSFW] Group scene for $actorName - waiting on model AcceptSex/RefuseSex (tier_prompt_sent=" . ($tierPromptSent ? "Y" : "N") . ")");
        }
    } else if (!empty($sceneActors) && count($sceneActors) > 1 && !$isSceneEvent && $intimacyStatus["level"] < 1) {
        // Scene actors exist but this is NOT a scene event - scene was rejected/cancelled
        // Clear the stale scene data
        error_log("[AIAGENTNSFW] Stale scene_actors detected for $actorName on non-scene event ({$gameRequest[0]}) - clearing");
        $intimacyStatus["scene_actors"] = null;
        $intimacyStatus["scene_phase"] = null;
        $intimacyStatus["skooma_addiction_bargain"] = false;
        $intimacyStatus["level"] = 0;
        updateIntimacyForActor($actorName, $intimacyStatus);
    }
}

// Force mood and inject prompts during scenes
// When arousal gating is ON: check level > 0
// When arousal gating is OFF: check if we're in a scene event
// SKIP if in pillow talk mode (scene just ended)
$isInActiveScene = isSexDisposalEnabled() ? ($intimacyStatus["level"] > 0) : $isSceneEvent;
if ($isInActiveScene && !$isPillowTalkMode && (int)($intimacyStatus["intensity_tier"] ?? 3) >= 3) {
    // Don't force sexy mood during tier_prompt - scene just started, nothing sexual yet
    $currentScenePhaseForMood = $intimacyStatus["scene_phase"] ?? null;
    if ($currentScenePhaseForMood !== "tier_prompt") {
        // A refused scene must NOT read as aroused - "sexy" is exactly what makes a non-consenting NPC act willing.
        // Key on the LIVE refusal state (forced_scene is deprecated/never set) so a refusing NPC is never forced sexy.
        $isRefusing = ($currentScenePhaseForMood === "rejected") || !empty($intimacyStatus["refusal_expressed"]);
        $GLOBALS["FORCE_MOOD"] = $isRefusing ? "afraid" : "sexy";
    }

    // ============================================
    // UNIVERSAL PROMPT INJECTION - ANY LEVEL > 0
    // ============================================
    // Sex prompts and affinity context should hit on EVERY request
    // during an active scene, regardless of level 1 or 2
    // ============================================
    // ============================================
    // INJECT SCENE CONTEXT - BUT WITHOUT TIER PROMPT FOR ENGAGED NPCs
    // ============================================
    // The tier prompt (accept/refuse instructions) is ONLY needed during tier_prompt phase.
    // Once accepted/engaged, we only need participant info - NOT the emotional_state block
    // that tells them to refuse. This fixes the bug where scene changes mid-sex
    // would re-inject "REFUSE SEX" instructions.
    // ============================================
    $sceneActorsForContext = $intimacyStatus["scene_actors"] ?? [];
    $currentScenePhase = $intimacyStatus["scene_phase"] ?? null;
    if (!empty($sceneActorsForContext)) {
        // Only inject FULL context (with tier prompt) during tier_prompt phase
        // For accepted/engaged phases, only inject participant info (no tier prompt)
        if ($currentScenePhase === "tier_prompt" && empty($tierContextAlreadyInjected)) {
            // Only inject if tier_prompt handler didn't already do it above
            $sceneContext = NsfwRelationship::buildSceneContext($actorName, $sceneActorsForContext, $isProstitute, $scenePromptContext);
            if (!empty($sceneContext)) {
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $sceneContext;
                error_log("[AIAGENTNSFW] Injected FULL <intimate_scene> context for $actorName (tier_prompt phase, universal block)");
            }
        } else if ($currentScenePhase === "tier_prompt" && !empty($tierContextAlreadyInjected)) {
            // Tier prompt handler already injected this - skip to avoid double injection
            error_log("[AIAGENTNSFW] Skipping tier prompt re-injection for $actorName (already injected by tier_prompt handler)");
        } else if ($isFrameworkForcedAggressor) {
            // Dedicated defeat route: identify the physical roles without rebuilding profile, orientation,
            // arousal, affinity, relationship tier, romance, payment, or refusal context.
            $defeatParticipants = array_values(array_filter(array_map('trim', $sceneActorsForContext), function ($sceneActor) use ($actorName) {
                return $sceneActor !== '' && strcasecmp($sceneActor, $actorName) !== 0;
            }));
            $defeatParticipantLines = ["Aggressor: {$actorName}", "Victim: " . ($GLOBALS['PLAYER_NAME'] ?? 'the player')];
            if (!empty($defeatParticipants)) {
                $defeatParticipantLines[] = "Scene participants: " . implode(', ', $defeatParticipants);
            }
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('defeat_scene_participants', implode("\n", $defeatParticipantLines));
            error_log("[AIAGENTNSFW] Injected defeat-role participant context for {$actorName}; REL participant profile excluded");
        } else {
            // Accepted/engaged ordinary route: inject participant info without the tier prompt.
            $participantResult = NsfwRelationship::buildMultiActorContext($actorName, $sceneActorsForContext, $isProstitute, $scenePromptContext);
            if (!empty($participantResult['context'])) {
                $participantContext = NsfwRelationship::wrapXml('intimate_scene', $participantResult['context']);
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $participantContext;
                error_log("[AIAGENTNSFW] Injected PARTICIPANT-ONLY context for $actorName (phase: $currentScenePhase)");
            }
        }
    }

    // Scene description injection moved OUTSIDE this block (see below)
    // so it applies to ALL events including orgasms, regardless of level

    // ============================================
    // PERSISTENT NON-CONSENT CONTEXT
    // ============================================
    // When forced_scene is true, the NPC refused but the scene continued.
    // Re-inject the non-consent/unwilling context on EVERY scene change
    // so the NPC consistently expresses being unwilling throughout.
    // ============================================
    // PLAYER PATH ONLY: non-consent / forced continuation is a player-scene concept. NPC-to-NPC scenes never use it.
    if (!$isFrameworkForcedAggressor && !empty($intimacyStatus["forced_scene"]) && empty($intimacyStatus["is_npc_scene"])) {
        $partnerName = $GLOBALS["PLAYER_NAME"] ?? "them";
        if (is_array($intimacyStatus["scene_actors"] ?? null)) {
            foreach ($intimacyStatus["scene_actors"] as $actor) {
                if (strtolower($actor) !== strtolower($actorName)) {
                    $partnerName = $actor;
                    break;
                }
            }
        }

        if (isNonConsentPromptEnabled()) {
            $nonConsentPrompt = NsfwData::getPrompt('non_consent');
            if (!empty($nonConsentPrompt)) {
                $nonConsentPrompt = str_replace('#PLAYER_NAME#', $GLOBALS["PLAYER_NAME"] ?? ($partnerName ?? 'Player'), $nonConsentPrompt);
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n<non_consent_context>\n{$nonConsentPrompt}\n</non_consent_context>";
                // Per-turn cue override so a forced NPC is never handed a "moan with pleasure" instruction. Reuses the
                // SAME configurable 'non_consent' prompt (UI-editable) - no hardcoded text. Re-applied every tick (bolster).
                $GLOBALS["AIAGENTNSFW_NONCONSENT_CUE"] = $nonConsentPrompt;
            }
        }
        error_log("[AIAGENTNSFW] Persistent non-consent context injected for $actorName (forced scene, every tick, player path)");
    }

    // ============================================
    // NPC TYPE SPECIFIC PROMPTS - PERSONALITY ONLY
    // ============================================
    // NOTE: setSexPrompt() and setSexSpeechStyle() are called ONCE
    // when scene is first accepted (lines 674-696 above).
    // DO NOT call them here - this block runs on EVERY event.
    // Only add slave-specific personality text here.
    // ============================================
    if (!$isFrameworkForcedAggressor && $isSlave && empty($slavePromptAlreadyInjected)) {
        // SLAVE: Add slave-specific personality and speech style
        // (skipped if already injected by accepted phase handler above)
        $slavePersonality = NsfwRelationship::getSlavePersonality($slaveAffinity, $slaveOwnerName);
        $slaveSpeech = NsfwRelationship::getSlaveSpeechStyle($slaveAffinity, $slaveOwnerName);
        if (!empty($slavePersonality)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('slave_personality', $slavePersonality);
        }
        if (!empty($slaveSpeech)) {
            $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . NsfwRelationship::wrapXml('slave_speech', $slaveSpeech);
        }
    }
} else {
    unset($GLOBALS["FORCE_MOOD"]);
}

// ============================================
// PHASE 3: ENGAGED (Level 2) - Kinks & Service Tracking
// ============================================
// Level 2 specific behavior:
// - Kinks engage (except slaves/prostitutes/default NPCs)
// - Prostitutes: service tracking (payment, time)
// - Override chatnf_sl cues for configured NPCs
// ============================================

// Determine if we should inject full speak style
// When arousal gating is ON: needs level 2 AND engaged phase
// When arousal gating is OFF: just needs engaged phase (no level check)
// SKIP if in pillow talk mode (scene just ended)
$arousalGatingEnabled = isSexDisposalEnabled();
$sceneActorsArray = $intimacyStatus["scene_actors"] ?? [];
$hasActiveScene = !empty($sceneActorsArray) && count($sceneActorsArray) > 0;
$isEngagedPhase = ($intimacyStatus["scene_phase"] ?? '') === "engaged";
$isNpcSceneSpeechRoute = !empty($intimacyStatus["is_npc_scene"]) && !$isNpcInviteRoute && ($isExplicitNpcPromptEvent || $npcSceneDialogueEnabledForPrompt);

// #6: don't inject engaged content on the bare acceptance turn (phase flips to engaged THIS request on a
// non-scene event). Ongoing sex turns (scene events, or rechat while already engaged) are unaffected.
$engagedSetThisRequest = (($intimacyStatus["scene_phase"] ?? '') === "engaged") && (($initialScenePhase ?? '') !== "engaged");
$shouldInjectEngagedContent = !$isPillowTalkMode && !($engagedSetThisRequest && !$isSceneEvent) && (
    ($arousalGatingEnabled && $intimacyStatus["level"] == 2 && $isEngagedPhase)
    || (!$arousalGatingEnabled && $isEngagedPhase)
    || $isNpcSceneSpeechRoute
);

if ($shouldInjectEngagedContent) {
    if (!$arousalGatingEnabled) {
        error_log("[AIAGENTNSFW] Phase: engaged (arousal gating OFF - phase only) for $actorName");
    } else {
        error_log("[AIAGENTNSFW] Phase: engaged (level 2) for $actorName");
    }

    // SLAVE SCENE CUES at Level 2
    if ($isSlave && !$isFrameworkForcedAggressor) {
        $slaveSceneCues = NsfwRelationship::getSlaveSceneCues($slaveAffinity, $slaveOwnerName);
        if (!empty($slaveSceneCues)) {
            // Override chatnf_sl cues with slave-specific cues
            $GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"] = "<response_instruction>\n{$slaveSceneCues}\n</response_instruction> " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
            error_log("[AIAGENTNSFW] Deferred SLAVE scene cues for $actorName (affinity: $slaveAffinity)");
        }
    }

    // KINKS ENGAGEMENT - only at level 2 for configured NPCs
    // (Sex prompts are now injected universally for any level > 0 above)
    if (!$isSlave && !$isProstitute) {
        $hasProfile = !empty($extended_data['source']) || !empty($extended_data['nsfw_source']); // read both keys
        if ($hasProfile) {
            error_log("[AIAGENTNSFW] Kinks engaged for configured NPC: $actorName");
        }
    }

    // PROSTITUTE SERVICE TRACKING
    if ($isProstitute && !$isFrameworkForcedAggressor) {
        // Build prostitute service context
        $serviceContext = "";

        // Payment status
        if (!empty($intimacyStatus["payment_confirmed"])) {
            $serviceContext .= (getGlobalPrompt('service_status_paid') ?: "Payment received and confirmed.") . " ";
        } else if (!empty($intimacyStatus["is_transaction"])) {
            // Only push "ensure you get paid" when payment is actually expected. At high affinity she may give
            // freely (auto-waived) or CHOOSE to (Devoted+, GiveFreeService) - so the negotiation context owns
            // that decision and we must NOT also hard-push payment here, or the two prompts contradict.
            $svcWaived = function_exists('aiagentNsfwProstitutePaymentWaived') && aiagentNsfwProstitutePaymentWaived($actorName);
            $svcAff = function_exists('getNpcAffinity') ? (int)getNpcAffinity($actorName) : 0;
            if (!$svcWaived && $svcAff < 76) {
                $serviceContext .= (getGlobalPrompt('service_status_unpaid') ?: "This is a business transaction - ensure you get paid for your services.") . " ";
            }
        }

        // Service duration tracking
        if (!empty($intimacyStatus["scene_start_time"])) {
            $duration = time() - $intimacyStatus["scene_start_time"];
            $minutes = floor($duration / 60);
            if ($minutes > 0) {
                $durationTmpl = getGlobalPrompt('service_status_duration') ?: "Session has been going for about #MINUTES# minutes.";
                $serviceContext .= str_replace('#MINUTES#', (string)$minutes, $durationTmpl) . " ";
            }
        }

        // Inject service context into personality
        if (!empty($serviceContext)) {
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n#Service Status: {$serviceContext}";
            error_log("[AIAGENTNSFW] Injected service context: {$serviceContext}");
        }
    }

    // CONSENT CLARITY (user directive 2026-06-28): once she has accepted, make it UNMISTAKABLE on every turn that
    // she already chose this, so she stays open for the rest of the scene instead of re-litigating consent. Sticky,
    // because accepted_sex holds until scene end. PLAYER-NPC, tier 3, non-prostitute only. UI-editable prompt key
    // 'consent_accepted_status' (falls back to the default below until wired into the Prompts panel).
    if (!$isFrameworkForcedAggressor && !empty($intimacyStatus["accepted_sex"]) && empty($intimacyStatus["is_npc_scene"]) && !$isProstitute
        && (int)($intimacyStatus["intensity_tier"] ?? 0) >= 3 && empty($intimacyStatus["scene_is_idle"])) {
        $consentStatusPrompt = '';
        if (function_exists('aiagentNsfwOpenMode') && aiagentNsfwOpenMode()
            && function_exists('aiagentNsfwIsProtectedNpc') && !aiagentNsfwIsProtectedNpc($actorName)
            && function_exists('aiagentNsfwBuildOpenModeScenePrompt')) {
            $openModePartner = (string)($GLOBALS["PLAYER_NAME"] ?? 'the player');
            $openModeAffinity = function_exists('getNpcAffinity') ? (int)getNpcAffinity($actorName) : 0;
            $openModeTier = function_exists('getAffinityTierName') ? getAffinityTierName($openModeAffinity) : '';
            $consentStatusPrompt = aiagentNsfwBuildOpenModeScenePrompt($actorName, $openModePartner, $openModeAffinity, $openModeTier);
        } else if (class_exists('NsfwData')) {
            $consentStatusPrompt = trim((string)NsfwData::getPrompt('consent_accepted_status'));
        }
        if ($consentStatusPrompt === '') {
            $consentStatusPrompt = "You have ALREADY chosen to be with #PLAYER_NAME# in this encounter - this was YOUR decision and it stands for the rest of this scene. You are a willing, present participant. Do not re-question or renegotiate consent, do not act surprised or violated, and do not pull back unless something genuinely new gives you a real reason. Stay in it.";
        }
        $consentStatusPrompt = str_replace('#PLAYER_NAME#', (string)($GLOBALS["PLAYER_NAME"] ?? 'them'), $consentStatusPrompt);
        $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('consent_status', $consentStatusPrompt);
        error_log("[AIAGENTNSFW] Injected sticky consent_status (already accepted) for {$GLOBALS["HERIKA_NAME"]}");
    }

    // Override chatnf_sl cues with NPC's speak style if configured
    // DEBUG: Log what we have for this NPC
    error_log("[AIAGENTNSFW] DEBUG speak style for {$GLOBALS["HERIKA_NAME"]}: sex_speech_style=" . ($extended_data["sex_speech_style"] ?? 'NOT SET'));

    // SPEAK STYLE = TIER 3 ONLY. Tier 1 (hug/affection) and Tier 2 (kiss/cuddle) scenes must NOT trigger the sex
    // speak-style, or she keeps moaning ("right there, just like that...") through a hug or after it ends.
    // intensity_tier is the authoritative scene tier (1/2 = affection, 3 = explicit), stored on the intimacy.
    // Speak style STAYS consent-gated (it poisons legit refusals): a PLAYER scene unlocks it only once she has
    // accepted (accepted_sex), or for the payment/forced classes (prostitute/slave).
    // NPC-to-NPC scenes are a SEPARATE path that never sets intensity_tier (that's a player-scene field), so the
    // tier>=3 test was always false for them and their configured style ("filthy", "passionate", ...) was thrown
    // away. Gate NPC scenes on their OWN active-sex signal instead: in an NPC scene, sex started, engaged/accepted,
    // not idle, and not currently refusing. This is what makes the NPC-scene speak style actually reach the model.
    $inActiveNpcSexScene = !empty($intimacyStatus["is_npc_scene"])
        && !empty($intimacyStatus["sex_started"])
        && empty($intimacyStatus["scene_is_idle"])
        && empty($intimacyStatus["refusal_expressed"])
        && in_array(strtolower((string)($intimacyStatus["scene_phase"] ?? '')), ['accepted', 'engaged'], true);
    $speakStyleTier3 = ((int)($intimacyStatus["intensity_tier"] ?? 0) >= 3
            && (!empty($intimacyStatus["accepted_sex"]) || $isProstitute || $isSlave))
        || $inActiveNpcSexScene;
    if ($speakStyleTier3 && !$isSlave && empty($GLOBALS["AIAGENTNSFW_STANDING_ONLY"]) && empty($GLOBALS["AIAGENTNSFW_PROSTITUTE_SERVICE_BEHAVIOR"]) && isset($extended_data["sex_speech_style"]) && !empty($extended_data["sex_speech_style"]) && $extended_data["sex_speech_style"] !== 'auto') {
        require_once __DIR__ . "/nsfw_data.php";

        // Get FULL speak style data (includes content, climax_prompt, pillow_talk_prompt, etc.)
        $speakStyleData = NsfwData::getSpeakStyle($extended_data["sex_speech_style"]) ?: [];
        $styleContent = trim((string)($speakStyleData['content'] ?? ''));

        error_log("[AIAGENTNSFW] DEBUG getSpeakStyle('{$extended_data["sex_speech_style"]}') returned: content=" . (!empty($speakStyleData['content']) ? strlen($speakStyleData['content']) . ' chars' : 'EMPTY') . ", climax_prompt=" . (!empty($speakStyleData['climax_prompt']) ? 'SET' : 'NOT SET'));

        // Fallback to file if not in JSONB
        if (empty($styleContent)) {
            $styleFile = __DIR__ . "/speakStyles/" . $extended_data["sex_speech_style"] . ".txt";
            if (file_exists($styleFile)) {
                $styleContent = file_get_contents($styleFile);
                error_log("[AIAGENTNSFW] DEBUG loaded from file: $styleFile");
            } else {
                error_log("[AIAGENTNSFW] DEBUG file not found: $styleFile");
            }
        }

        if (empty(trim($styleContent))) {
            $styleLabel = trim((string)($speakStyleData['description'] ?? $extended_data["sex_speech_style"]));
            $styleContent = "Use the selected sex speech style '{$styleLabel}'. Stay focused on the current intimate scene with #PRIMARY_PARTNER#. React to the physical action, your personality, and your feelings toward them. Keep it brief and in-character.";
            error_log("[AIAGENTNSFW] Synthesized fallback scene cue from empty speak style '{$extended_data["sex_speech_style"]}' for {$GLOBALS["HERIKA_NAME"]}");
        }

        if (!empty($styleContent)) {
            $styleContent = aiagentNsfwResolveSpeakStylePlaceholders($styleContent, $actorName, $intimacyStatus);
            // Override the chatnf_sl cue with the NPC's speak style (deferred - clobbered otherwise)
            $styleTag = !empty($intimacyStatus["is_npc_scene"]) ? "npc_scene_instruction" : "response_instruction";
            $styledCue = "<{$styleTag}>\n{$styleContent}\n</{$styleTag}> " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
            $GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"] = $styledCue;

            // For chatnf_sl_climax (legacy climax event), use the specific climax_prompt if available
            // NOTE: ext_nsfw_orgasm is handled ENTIRELY by nsfw_ostim_handler.php which knows
            // whether the NPC is orgasming (uses climax_prompt) or reacting to partner (uses partner_climax_prompt)
            // DO NOT touch ext_nsfw_orgasm here - it will overwrite the handler's correct prompt!
            $climaxPrompt = $speakStyleData['climax_prompt'] ?? '';
            if (!empty($climaxPrompt)) {
                $climaxPrompt = aiagentNsfwResolveSpeakStylePlaceholders($climaxPrompt, $actorName, $intimacyStatus);
                $climaxCue = "<response_instruction>\n{$climaxPrompt}\n</response_instruction> " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
                $GLOBALS["AIAGENTNSFW_SCENE_CLIMAX_OVERRIDE"] = $climaxCue;
                error_log("[AIAGENTNSFW] Deferred chatnf_sl_climax climax_prompt for {$GLOBALS["HERIKA_NAME"]}");
            } else {
                // No specific climax_prompt - use general speak style (deferred)
                $GLOBALS["AIAGENTNSFW_SCENE_CLIMAX_OVERRIDE"] = $styledCue;
            }

            error_log("[AIAGENTNSFW] Overriding chatnf_sl with speak style '{$extended_data["sex_speech_style"]}' for {$GLOBALS["HERIKA_NAME"]}");
        } else {
            error_log("[AIAGENTNSFW] WARNING: NPC has sex_speech_style='{$extended_data["sex_speech_style"]}' but content is empty!");
        }
    } else {
        error_log("[AIAGENTNSFW] DEBUG: NPC {$GLOBALS["HERIKA_NAME"]} has no configured sex_speech_style - using defaults");
    }

    // HARD STATE MACHINE (user directive 2026-06-29): in a PLAYER decision phase (tier 3, undecided, not refusing),
    // the speak-style + climax overrides must NOT be present - only the dumb accept/refuse cue (TIER_CUE_OVERRIDE,
    // which already wins for ext_nsfw_sexcene). Strip them so no sex-style cue can leak before she chooses. The
    // matching toolset strip to AcceptSex/RefuseSex is in functions.php; both gate on the same condition.
    if (empty($intimacyStatus["is_npc_scene"]) && (int)($intimacyStatus["intensity_tier"] ?? 0) >= 3
        && empty($intimacyStatus["accepted_sex"]) && empty($intimacyStatus["refusal_expressed"])
        && empty($intimacyStatus["scene_is_idle"]) && !$isSlave && !$isProstitute) {
        unset($GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"]);
        unset($GLOBALS["AIAGENTNSFW_SCENE_CLIMAX_OVERRIDE"]);
        error_log("[AIAGENTNSFW] PLAYER DECISION PHASE for {$GLOBALS["HERIKA_NAME"]}: speak-style + climax overrides stripped (awaiting Accept/Refuse)");
    }

    // ============================================
    // CRITICAL: Also override RECHAT cues for engaged NPCs
    // ============================================
    // NPCs often talk via rechat (regular multi-NPC chat system) rather than
    // chatnf_sl (OStim auto-talk). Without this, they get generic "dialogue turn"
    // cues instead of sex scene cues - causing them to talk about random topics
    // like "ancient resonance" instead of the intimate scene.
    // ============================================
    // DEFERRED: the final chatnf_sl cue isn't known until prompts.php rebuilds it; flag it and
    // prompts.php mirrors the resolved chatnf_sl onto rechat at the end.
    $GLOBALS["AIAGENTNSFW_RECHAT_SEX"] = true;
}

// ============================================
// SCENE FENCE — clear lingering scene state once the scene is actually over
// ============================================
// Some scenes end WITHOUT a chatnf_sl_end (OAR affection that just stops, NPC-aborted
// prostitution, a scene the player walks out of), so handleSceneEnd() never runs and
// current_scene_desc / scene_actors / is_active_participant linger. That keeps injecting
// scene context into ordinary chat = the NPC "still talks about the scene after getting out".
// THE THREAD LEDGER IS THE AUTHORITY: if it says this actor is not in any live scene thread,
// the per-NPC scene fields are stale cache and must be wiped before they reach the prompt. An
// NPC only leaks on its own turn, which is exactly when this runs for it. Does NOT touch
// pillow_talk_* (that one-shot latch is consumed earlier, at ~line 534, and is independent of
// scene fields). During a live scene the ledger thread is active (upserted by handleSceneUpdate
// earlier this same request), so nothing is cleared.
if (!aiagentNsfwActorInLiveScene($actorName)) {
    $hasLingeringScene = !empty($intimacyStatus["current_scene_desc"])
        || !empty($intimacyStatus["current_scene_name"])
        || !empty($intimacyStatus["scene_actors"])
        || !empty($intimacyStatus["is_active_participant"])
        || in_array(strtolower((string)($intimacyStatus["scene_phase"] ?? '')), ['affection', 'tier_prompt', 'accepted', 'engaged', 'rejected'], true);
    // Prostitution is the ONLY scene type with a payment state machine, and it lives independently
    // of the scene fields: while is_transaction/payment_pending linger with payment unconfirmed,
    // prerequest keeps injecting "this is a business transaction - get paid" (~line 2120) and the
    // payment guard (~line 990) holds her in negotiation, so she hounds for gold instead of talking
    // normally = "after a prostitution scene I cannot talk anymore". No live scene = no deal in
    // progress (a real negotiation/paid scene keeps its ledger thread alive), so wipe it too.
    $hasLingeringTxn = !empty($intimacyStatus["is_transaction"])
        || !empty($intimacyStatus["payment_pending"])
        || !empty($intimacyStatus["negotiation_phase"])
        || !empty($intimacyStatus["ready_for_service"])
        || !empty($intimacyStatus["payment_confirmed"])
        || !empty($intimacyStatus["payment_failed"]);
    if ($hasLingeringScene || $hasLingeringTxn) {
        $intimacyStatus["current_scene_desc"] = null;
        $intimacyStatus["current_scene_name"] = null;
        $intimacyStatus["current_scene_tags"] = null;
        $intimacyStatus["current_scene_thread_key"] = null;
        $intimacyStatus["scene_actors"] = null;
        $intimacyStatus["raw_scene_actor_slots"] = null;
        $intimacyStatus["current_primary_partner"] = null;
        $intimacyStatus["is_active_participant"] = false;
        $intimacyStatus["had_sex_in_scene"] = false;
        $intimacyStatus["scene_phase"] = null;
        $intimacyStatus["scene_is_idle"] = null;
        // Clear the prostitution payment/transaction state machine - no live scene = no deal in progress.
        $intimacyStatus["is_transaction"] = false;
        $intimacyStatus["transaction_client"] = null;
        $intimacyStatus["negotiation_phase"] = false;
        $intimacyStatus["ready_for_service"] = false;
        $intimacyStatus["payment_pending"] = false;
        $intimacyStatus["payment_pending_amount"] = null;
        $intimacyStatus["payment_pending_service"] = null;
        $intimacyStatus["payment_confirmed"] = false;
        $intimacyStatus["payment_confirmed_amount"] = null;
        $intimacyStatus["payment_confirmed_time"] = null;
        $intimacyStatus["payment_service"] = null;
        $intimacyStatus["payment_failed"] = false;
        $intimacyStatus["payment_failure_reason"] = null;
        $intimacyStatus["payment_failed_amount"] = null;
        $intimacyStatus["payment_failed_time"] = null;
        $scenePhase = null;
        error_log("[AIAGENTNSFW] Scene fence: cleared lingering scene/transaction state for {$actorName} (no live scene)");
    }
}

// ============================================
// INJECT CURRENT SCENE DESCRIPTION — FOR ALL EVENTS
// ============================================
// Runs OUTSIDE the level-gated block so orgasms, climax events, and all
// scene-related events get the scene context. Without this, the model
// has no idea what position/animation is happening or who the partners are.
// ============================================
$storedSceneDesc = $intimacyStatus["current_scene_desc"] ?? null;
$storedSceneTags = $intimacyStatus["current_scene_tags"] ?? [];
$storedSceneActors = $intimacyStatus["scene_actors"] ?? [];
$storedPrimaryPartner = $intimacyStatus["current_primary_partner"] ?? null;
if (!empty($storedSceneDesc) && !empty($storedSceneActors) && !$isPillowTalkMode && (int)($intimacyStatus["intensity_tier"] ?? 3) >= 3) {
    // Always exclude the NPC who is RECEIVING this prompt, so the "with X" names her actual partner. For a PLAYER
    // scene the recipient is the NPC and her partner is the player; the old code excluded the PLAYER instead, so the
    // header read "INTIMATE SCENE with <herself>" and she couldn't tell who she was with. (user report 2026-06-29)
    $excludeSceneName = $actorName;
    $partnerNames = array_filter($storedSceneActors, function($a) use ($excludeSceneName) { return strtolower($a) !== strtolower($excludeSceneName); });
    // Highlight primary partner from OStim's actor ordering
    if ($storedPrimaryPartner && count($partnerNames) > 1) {
        $otherPartners = array_filter($partnerNames, function($a) use ($storedPrimaryPartner) { return $a !== $storedPrimaryPartner; });
        $partnerStr = "$storedPrimaryPartner (primary) and " . implode(", ", $otherPartners);
    } else {
        $partnerStr = $storedPrimaryPartner ?? (!empty($partnerNames) ? implode(" and ", $partnerNames) : "partner");
    }
    $sceneDescBlock = "<current_scene>\nINTIMATE SCENE with $partnerStr: $storedSceneDesc";
    if (!empty($storedSceneTags)) {
        $sceneDescBlock .= "\nScene tags: " . implode(", ", $storedSceneTags);
    }
    $sceneDescBlock .= "\n</current_scene>";
    $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $sceneDescBlock;
}

if (isset($extended_data["fertility_recent_birth"])) {
    error_log("[AIAGENT NSFW] Checking fertility_recent_birth");
    if (($gameRequest[2]-$extended_data["fertility_recent_birth"]) < (7 * 24 / 0.0000024)) {
        error_log("[AIAGENT NSFW] setBirthPrompt fertility_recent_birth");
        setBirthPrompt($GLOBALS["HERIKA_NAME"]);
    }
}


error_log("[AIAGENTNSFW ] updateIntimacyForActor({$GLOBALS["HERIKA_NAME"]})".json_encode($intimacyStatus));
updateIntimacyForActor($GLOBALS["HERIKA_NAME"],$intimacyStatus);        

// Add hook  to XTTS to insert some oh's and ah's into the speech.
// Also will change XTTS settings
// If level 2 -> Intimate scene, NPC should talk slower, and we add some random gasps.
// Respects XTTS_MODIFY_LEVEL1 and XTTS_MODIFY_LEVEL2 settings from config

// Load XTTS settings from database
$xttsSettingsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_settings'");
$xttsLevel1Enabled = true;  // Default to enabled
$xttsLevel2Enabled = true;  // Default to enabled
if ($xttsSettingsRow && !empty($xttsSettingsRow['value'])) {
    $xttsSettings = json_decode($xttsSettingsRow['value'], true);
    if (is_array($xttsSettings)) {
        $xttsLevel1Enabled = isset($xttsSettings['XTTS_MODIFY_LEVEL1']) ? (bool)$xttsSettings['XTTS_MODIFY_LEVEL1'] : true;
        $xttsLevel2Enabled = isset($xttsSettings['XTTS_MODIFY_LEVEL2']) ? (bool)$xttsSettings['XTTS_MODIFY_LEVEL2'] : true;
    }
}

// Load random moans settings from Settings JSONB (same source as XTTS settings)
$enableRandomMoans = true;  // Default enabled
$moansAffinityThreshold = 6;  // Default: Acquaintance
$randomMoansList = [" ... oh ... ", " ... ah ... ", " ... mmm ... "];  // Default moans
$xttsSpeedLevel1 = 0.8;  // Default speed for level 1 (idle/foreplay)
$xttsSpeedLevel2 = 0.7;  // Default speed for level 2 (action)

if ($xttsSettingsRow && !empty($xttsSettingsRow['value'])) {
    $xttsSettings = json_decode($xttsSettingsRow['value'], true);
    if (is_array($xttsSettings)) {
        $enableRandomMoans = !isset($xttsSettings['ENABLE_RANDOM_MOANS']) || $xttsSettings['ENABLE_RANDOM_MOANS'] === true || $xttsSettings['ENABLE_RANDOM_MOANS'] === 'true';
        $moansAffinityThreshold = isset($xttsSettings['MOANS_AFFINITY_THRESHOLD']) ? (int)$xttsSettings['MOANS_AFFINITY_THRESHOLD'] : 6;
        $xttsSpeedLevel1 = isset($xttsSettings['XTTS_SPEED_LEVEL1']) ? (float)$xttsSettings['XTTS_SPEED_LEVEL1'] : 0.8;
        $xttsSpeedLevel2 = isset($xttsSettings['XTTS_SPEED_LEVEL2']) ? (float)$xttsSettings['XTTS_SPEED_LEVEL2'] : 0.7;
        if (!empty($xttsSettings['RANDOM_MOAN_SOUNDS'])) {
            $randomMoansList = array_filter(array_map('trim', explode("\n", $xttsSettings['RANDOM_MOAN_SOUNDS'])));
            if (empty($randomMoansList)) {
                $randomMoansList = [" ... oh ... ", " ... ah ... ", " ... mmm ... "];
            }
        }
    }
}

// Check NPC's affinity with scene partner(s) against threshold
$npcAffinityMeetsMoanThreshold = false;
if ($enableRandomMoans) {
    $actorName = $GLOBALS["HERIKA_NAME"] ?? '';
    if (!empty($actorName) && isset($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"]) && is_array($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"])) {
        // Check affinity with each partner - use lowest (most restrictive)
        $lowestAffinity = 100;
        foreach ($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] as $partner) {
            if (strtolower($partner) !== strtolower($actorName)) {
                $partnerAffinity = getNpcAffinity($actorName, $partner);
                if ($partnerAffinity < $lowestAffinity) {
                    $lowestAffinity = $partnerAffinity;
                }
            }
        }
        $npcAffinityMeetsMoanThreshold = ($lowestAffinity >= $moansAffinityThreshold);
        error_log("[AIAGENTNSFW] Moans affinity check: $actorName lowest affinity = $lowestAffinity, threshold = $moansAffinityThreshold, passes = " . ($npcAffinityMeetsMoanThreshold ? 'YES' : 'NO'));
    } else {
        // Fallback: check player affinity
        $npcAffinity = getNpcAffinity($actorName);
        $npcAffinityMeetsMoanThreshold = ($npcAffinity >= $moansAffinityThreshold);
        error_log("[AIAGENTNSFW] Moans affinity check (fallback): $actorName affinity = $npcAffinity, threshold = $moansAffinityThreshold, passes = " . ($npcAffinityMeetsMoanThreshold ? 'YES' : 'NO'));
    }
}

// ============================================
// XTTS VOICE MODIFICATIONS
// ============================================
// Work independently of arousal gating - tied to scene presence, not arousal levels.
// When arousal gating is ON: Level 2 = in scene, Level 1 = foreplay
// When arousal gating is OFF: Any scene event = "in scene" (no foreplay phase exists)
// ============================================
$xttsInScene = isSexDisposalEnabled() ? ($intimacyStatus["level"] == 2) : $isSceneEvent;
$xttsInForeplay = isSexDisposalEnabled() ? ($intimacyStatus["level"] == 1) : false;  // Foreplay only exists with arousal gating

// XTTS Level 2 = In active sex scene (moans, speed changes)
if ($xttsInScene && $xttsLevel2Enabled) {
    $moansActive = $enableRandomMoans && $npcAffinityMeetsMoanThreshold;
    error_log("Adding XTTS hook (in scene) (XTTS_MODIFY_LEVEL2 enabled, speed: {$xttsSpeedLevel2}, random moans: " . ($moansActive ? 'ON' : 'OFF - affinity too low') . ")");

    // Store in GLOBALS so the closure can access them
    $GLOBALS["AIAGENTNSFW_ENABLE_RANDOM_MOANS"] = $moansActive;
    $GLOBALS["AIAGENTNSFW_RANDOM_MOANS_LIST"] = $randomMoansList;
    $GLOBALS["AIAGENTNSFW_XTTS_SPEED_LEVEL2"] = $xttsSpeedLevel2;

    $GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"][]=function($text) {
        $result = $text;

        // Only inject moans if enabled AND affinity threshold met
        if (!empty($GLOBALS["AIAGENTNSFW_ENABLE_RANDOM_MOANS"])) {
            $randomStrings = $GLOBALS["AIAGENTNSFW_RANDOM_MOANS_LIST"] ?? [" ... oh ... ", " ... ah ... ", " ... mmm ... "];

            // Generate a random index
            $randomIndex = mt_rand(0, count($randomStrings) - 1);

            // Split the sentence into an array of words
            $words = explode(' ', $text);

            // Select a random word index to insert the random string
            $wordIndex = mt_rand(0, count($words) - 1);

            // Insert the random string into the selected word
            $randomWord = $words[$wordIndex];
            $insertPosition = strpos($result, $randomWord);
            $result = substr_replace($result, $randomStrings[$randomIndex], $insertPosition, 0);
            Logger::info("Applying text modifier for XTTS $text => $result ".__FILE__);
        }

        $speed = $GLOBALS["AIAGENTNSFW_XTTS_SPEED_LEVEL2"] ?? 0.7;
        apply_tts_settings(["temperature"=>1,"speed"=>$speed,"enable_text_splitting"=>false,"top_p"=> 1,"top_k"=>100],true);
        return $result;

    };
} else if ($xttsInScene && !$xttsLevel2Enabled) {
    error_log("[AIAGENTNSFW] XTTS hook (in scene) DISABLED by settings");
}

// XTTS Level 1 = Foreplay (slower voice, no moans) - ONLY applies when arousal gating is ON
if ($xttsInForeplay && $xttsLevel1Enabled) {
    error_log("Adding XTTS hook (foreplay) (XTTS_MODIFY_LEVEL1 enabled, speed: {$xttsSpeedLevel1})");
    $GLOBALS["AIAGENTNSFW_XTTS_SPEED_LEVEL1"] = $xttsSpeedLevel1;

    $GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"][]=function($text) {
        $speed = $GLOBALS["AIAGENTNSFW_XTTS_SPEED_LEVEL1"] ?? 0.8;
        Logger::info("Applying speed modifier for XTTS (speed: {$speed}) $text => $text ".__FILE__);

        apply_tts_settings(["temperature"=>1,"speed"=>$speed,"enable_text_splitting"=>false,"top_p"=> 1,"top_k"=>100],true);
        return $text;

    };
} else if ($xttsInForeplay && !$xttsLevel1Enabled) {
    error_log("[AIAGENTNSFW] XTTS hook (foreplay) DISABLED by settings");
}

// ============================================
// RESET XTTS SPEED when not in a scene
// ============================================
// This ensures XTTS goes back to normal speed after a scene ends.
// Without this, the slow speed from the previous scene persists.
// When arousal gating is ON: level 0 means not in scene
// When arousal gating is OFF: not being in a scene event means not in scene
// ============================================
$xttsNotInScene = isSexDisposalEnabled() ? ($intimacyStatus["level"] == 0) : !$isSceneEvent;
if ($xttsNotInScene) {
    // Add a hook that resets speed to normal (1.0)
    $GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"][] = function($text) {
        // Reset to normal TTS settings
        apply_tts_settings(["temperature" => 0.75, "speed" => 1.0, "enable_text_splitting" => true, "top_p" => 0.85, "top_k" => 50], true);
        return $text;
    };
}


?>
