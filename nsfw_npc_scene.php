<?php
/**
 * NSFW NPC-to-NPC Scene Handler
 *
 * Processes NPC-only sex scenes detected by OStim NPCs mod.
 * Checks affinity between participants for prompt/context selection.
 * OStim NPC expansion owns mechanical scene movement; this route should
 * not promise hard refusal/thread stop behavior unless that control is
 * explicitly made reliable later.
 *
 * Raw data format from PSC (uses ^ delimiter to avoid CHIM pipe conflict):
     * - npc1name^npc2name^threadID^sceneID^npc1ToNpc2Rank^npc2ToNpc1Rank^dist=playerDistance
 */

require_once(__DIR__ . '/nsfw_relationship.php');

if (!function_exists('aiagentNsfwCleanSceneName')) {
    function aiagentNsfwCleanSceneName($name) {
        $name = trim((string)$name);
        if ($name === '') {
            return '';
        }
        $name = preg_replace('/^\(Context[^)]*\)\s*/u', '', $name);
        if (strpos($name, ':') !== false) {
            $name = trim(substr($name, strrpos($name, ':') + 1));
        }
        return trim($name);
    }
}

if (!function_exists('aiagentNsfwSceneActorList')) {
    function aiagentNsfwSceneActorList($actorName, $intimacyStatus = [], $extra = []) {
        $actors = $extra['scene_actors']
            ?? ($intimacyStatus['scene_actors'] ?? ($GLOBALS['AIAGENTNSFW_SCENE_ACTORS'] ?? []));
        if (!is_array($actors)) {
            $actors = [];
        }

        $cleanActors = [];
        foreach ($actors as $actor) {
            $actor = aiagentNsfwCleanSceneName($actor);
            if ($actor === '') {
                continue;
            }
            if (function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($actor)) {
                continue;
            }
            $alreadyAdded = false;
            foreach ($cleanActors as $existing) {
                if (strcasecmp($existing, $actor) === 0) {
                    $alreadyAdded = true;
                    break;
                }
            }
            if (!$alreadyAdded) {
                $cleanActors[] = $actor;
            }
        }

        $actorName = aiagentNsfwCleanSceneName($actorName);
        if ($actorName !== '') {
            $hasSpeaker = false;
            foreach ($cleanActors as $existing) {
                if (strcasecmp($existing, $actorName) === 0) {
                    $hasSpeaker = true;
                    break;
                }
            }
            if (!$hasSpeaker) {
                array_unshift($cleanActors, $actorName);
            }
        }

        return $cleanActors;
    }
}

if (!function_exists('aiagentNsfwFirstNonEmpty')) {
    function aiagentNsfwFirstNonEmpty($values) {
        foreach ($values as $value) {
            $value = aiagentNsfwCleanSceneName($value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
}

if (!function_exists('aiagentNsfwBuildScenePlaceholderMap')) {
    function aiagentNsfwBuildScenePlaceholderMap($actorName, $intimacyStatus = [], $extra = []) {
        $actorName = aiagentNsfwCleanSceneName($actorName ?: ($GLOBALS['HERIKA_NAME'] ?? ''));
        $playerName = aiagentNsfwCleanSceneName($extra['player_name'] ?? ($GLOBALS['PLAYER_NAME'] ?? 'Player'));
        if ($playerName === '') {
            $playerName = 'Player';
        }

        $actors = aiagentNsfwSceneActorList($actorName, $intimacyStatus, $extra);
        $playerInScene = false;
        foreach ($actors as $actor) {
            if (strcasecmp($actor, $playerName) === 0) {
                $playerInScene = true;
                break;
            }
        }
        $isNpcScene = !empty($extra['is_npc_scene'])
            || !empty($intimacyStatus['is_npc_scene'])
            || (!empty($GLOBALS['AIAGENTNSFW_NPC_SCENE']) && !$playerInScene);

        $otherActors = [];
        $playerPartners = [];
        foreach ($actors as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if ($actorName !== '' && strcasecmp($candidate, $actorName) === 0) {
                continue;
            }
            if (strcasecmp($candidate, $playerName) !== 0) {
                $playerPartners[] = $candidate;
            }
            $otherActors[] = $candidate;
        }

        $npcPartner = aiagentNsfwFirstNonEmpty([
            $extra['npc_partner'] ?? null,
            $extra['primary_partner'] ?? null,
            $intimacyStatus['npc_scene_partner'] ?? null,
            $intimacyStatus['npc_partner'] ?? null,
        ]);
        if ($npcPartner === '') {
            foreach ($otherActors as $candidate) {
                if (strcasecmp($candidate, $playerName) !== 0) {
                    $npcPartner = $candidate;
                    break;
                }
            }
        }
        if ($npcPartner === '') {
            $npcPartner = 'the other NPC';
        }

        if ($isNpcScene) {
            $primaryPartner = aiagentNsfwFirstNonEmpty([
                $extra['primary_partner'] ?? null,
                $intimacyStatus['current_primary_partner'] ?? null,
                $intimacyStatus['sex_partner'] ?? null,
                $npcPartner,
            ]);
            // NPC-SCENE GUARD (fix 2026-07-01k): the player can NEVER be the partner on this route. Stale
            // player-scene remnants in partner fields made concurrent-scene NPCs talk as if in the PLAYER's
            // scene ("scene bleed"). Re-resolve to the first non-player co-actor.
            if ($primaryPartner !== '' && strcasecmp($primaryPartner, $playerName) === 0) {
                $primaryPartner = $playerPartners[0] ?? 'the other NPC';
            }
            if ($npcPartner !== '' && strcasecmp($npcPartner, $playerName) === 0) {
                $npcPartner = $playerPartners[0] ?? 'the other NPC';
            }
        } else {
            // In player scenes, current_primary_partner is the player-focused NPC
            // from OStim ordering, not the speaking NPC's partner. Speech styles
            // must resolve their active partner to the human player on this route.
            $primaryPartner = aiagentNsfwFirstNonEmpty([
                $extra['primary_partner'] ?? null,
                $intimacyStatus['sex_partner'] ?? null,
                $playerName,
            ]);
        }

        $orgasmerName = aiagentNsfwFirstNonEmpty([
            $extra['orgasmer_name'] ?? null,
            $extra['orgasm_partner'] ?? null,
        ]);
        if ($orgasmerName === '') {
            $orgasmerName = $primaryPartner;
        }

        $sceneDesc = trim((string)($extra['scene_description']
            ?? ($intimacyStatus['current_scene_desc'] ?? '')));
        $sceneID = trim((string)($extra['scene_id']
            ?? ($intimacyStatus['current_scene_name'] ?? ($intimacyStatus['npc_scene_id'] ?? ''))));
        $threadID = trim((string)($extra['thread_id']
            ?? ($intimacyStatus['npc_scene_thread_id'] ?? ($GLOBALS['AIAGENTNSFW_NPC_THREAD_ID'] ?? ''))));

        $inviteRole = trim((string)($extra['npc_invite_role'] ?? ($intimacyStatus['npc_invite_role'] ?? ($GLOBALS['AIAGENTNSFW_NPC_INVITE_ROLE'] ?? ''))));
        $inviteInitiator = aiagentNsfwFirstNonEmpty([
            $extra['npc_invite_initiator'] ?? null,
            $intimacyStatus['npc_invite_initiator'] ?? null,
            $GLOBALS['AIAGENTNSFW_NPC_INVITE_INITIATOR'] ?? null,
        ]);
        $inviteTarget = aiagentNsfwFirstNonEmpty([
            $extra['npc_invite_target'] ?? null,
            $intimacyStatus['npc_invite_target'] ?? null,
            $GLOBALS['AIAGENTNSFW_NPC_INVITE_TARGET'] ?? null,
        ]);
        $inviteAction = 'in an NPC invite/walk-to phase with';
        if ($inviteRole === 'initiator') {
            $inviteAction = 'walking toward or inviting';
        } else if ($inviteRole === 'recipient') {
            $inviteAction = 'being approached or invited by';
        }

        $spouseName = '';
        if (class_exists('NsfwRelationship') && method_exists('NsfwRelationship', 'getSpouseName') && $actorName !== '') {
            $spouseName = trim((string)NsfwRelationship::getSpouseName($actorName));
        }

        $map = [
            '#NPC_NAME#' => $actorName,
            '#SPEAKER_NAME#' => $actorName,
            '#PLAYER_NAME#' => $playerName,
            '#PRIMARY_PARTNER#' => $primaryPartner,
            '#NPC_PARTNER#' => $npcPartner,
            '#ORGASMER_NAME#' => $orgasmerName,
            '#NPC_SPOUSE#' => $spouseName,
            '#SPOUSE#' => $spouseName,
            '#NPC_SCENE_PARTICIPANTS#' => implode(', ', $actors),
            '#SCENE_PARTICIPANTS#' => implode(', ', $actors),
            '#OTHER_PARTICIPANTS#' => implode(', ', $otherActors),
            '#CURRENT_SCENE#' => $sceneDesc,
            '#SCENE_DESCRIPTION#' => $sceneDesc,
            '#SCENE_ID#' => $sceneID,
            '#THREAD_ID#' => $threadID,
            '#NPC_INVITE_ROLE#' => $inviteRole !== '' ? $inviteRole : 'participant',
            '#NPC_INVITE_ACTION#' => $inviteAction,
            '#NPC_INVITE_INITIATOR#' => $inviteInitiator !== '' ? $inviteInitiator : $actorName,
            '#NPC_INVITE_TARGET#' => $inviteTarget !== '' ? $inviteTarget : $npcPartner,
        ];

        for ($i = 0; $i < 6; $i++) {
            $num = $i + 1;
            $map["#NPC_NAME_{$num}#"] = $actors[$i] ?? '';
            $map["#NPC_PARTNER_{$num}#"] = $otherActors[$i] ?? '';
            $map["#PLAYER_PARTNER_{$num}#"] = $playerPartners[$i] ?? '';
            $map["#NPC_NAME{$num}#"] = $actors[$i] ?? '';
            $map["#NPC_PARTNER{$num}#"] = $otherActors[$i] ?? '';
            $map["#PLAYER_PARTNER{$num}#"] = $playerPartners[$i] ?? '';
            $map["#ACTOR_{$i}#"] = $actors[$i] ?? '';
            $map["#ACTOR{$i}#"] = $actors[$i] ?? '';
        }

        return $map;
    }
}

if (!function_exists('aiagentNsfwResolveScenePlaceholders')) {
    function aiagentNsfwResolveScenePlaceholders($prompt, $actorName, $intimacyStatus = [], $extra = []) {
        $prompt = (string)$prompt;
        if (trim($prompt) === '') {
            return '';
        }
        $map = aiagentNsfwBuildScenePlaceholderMap($actorName, $intimacyStatus, $extra);
        return strtr($prompt, $map);
    }
}

if (!function_exists('aiagentNsfwResolveSpeakStylePlaceholders')) {
    function aiagentNsfwResolveSpeakStylePlaceholders($prompt, $actorName, $intimacyStatus = [], $extra = []) {
        $prompt = (string)$prompt;
        if (trim($prompt) === '') {
            return '';
        }

        $map = aiagentNsfwBuildScenePlaceholderMap($actorName, $intimacyStatus, $extra);
        $map = array_intersect_key($map, array_flip([
            '#NPC_NAME#',
            '#SPEAKER_NAME#',
            '#PLAYER_NAME#',
            '#PRIMARY_PARTNER#',
            '#ORGASMER_NAME#',
            '#NPC_SPOUSE#',
            '#SPOUSE#',
            '#SCENE_PARTICIPANTS#',
            '#CURRENT_SCENE#',
            '#SCENE_DESCRIPTION#',
            '#SCENE_ID#',
            '#THREAD_ID#',
        ]));
        return strtr($prompt, $map);
    }
}

if (!function_exists('aiagentNsfwRenderNpcScenePrompt')) {
    function aiagentNsfwRenderNpcScenePrompt($prompt, $actorName, $intimacyStatus = [], $extra = []) {
        $extra['is_npc_scene'] = true;
        return aiagentNsfwResolveScenePlaceholders($prompt, $actorName, $intimacyStatus, $extra);
    }
}

class NsfwNpcScene {

    private static function decodePayloadJson($encoded) {
        $encoded = trim((string)$encoded);
        if ($encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            $decoded = rawurldecode($encoded);
        }

        $data = json_decode((string)$decoded, true);
        // CHIM's PSC->server transport escapes the double-quotes in the actors= payload (["A"] arrives as
        // [\"A\"]), which is invalid JSON and dropped the 3rd+ actor (3-way scenes registered only 2). Retry
        // with the backslash-escaping stripped.
        if (!is_array($data) && strpos((string)$decoded, '\\') !== false) {
            $data = json_decode(stripslashes((string)$decoded), true);
        }
        return is_array($data) ? $data : null;
    }

    private static function cleanActorList($actors) {
        $cleanActors = [];
        if (!is_array($actors)) {
            return $cleanActors;
        }

        foreach ($actors as $actor) {
            if (is_array($actor)) {
                $actor = $actor['name'] ?? '';
            }
            $actor = self::cleanNpcName($actor);
            if ($actor === '' || nsfwIsNarratorName($actor)) {
                continue;
            }
            $exists = false;
            foreach ($cleanActors as $existing) {
                if (strcasecmp($existing, $actor) === 0) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $cleanActors[] = $actor;
            }
        }

        return $cleanActors;
    }

    public static function resolvePrimaryPartnerForActor($actorName, $actorList, $sceneID = '') {
        $actorName = self::cleanNpcName($actorName);
        $actorList = self::cleanActorList($actorList);
        if ($actorName === '' || empty($actorList)) {
            return '';
        }

        $actorIndex = null;
        foreach ($actorList as $index => $candidate) {
            if (strcasecmp($candidate, $actorName) === 0) {
                $actorIndex = $index;
                break;
            }
        }

        if ($actorIndex !== null && $sceneID !== '' && function_exists('getSceneData')) {
            $sceneData = getSceneData($sceneID);
            $actions = is_array($sceneData['actions'] ?? null) ? $sceneData['actions'] : [];
            foreach ($actions as $action) {
                $source = isset($action['actor']) ? (int)$action['actor'] : 0;
                $target = isset($action['target']) ? (int)$action['target'] : 1;
                if ($source === $actorIndex && isset($actorList[$target])) {
                    return $actorList[$target];
                }
                if ($target === $actorIndex && isset($actorList[$source])) {
                    return $actorList[$source];
                }
            }
        }

        foreach ($actorList as $candidate) {
            if (strcasecmp($candidate, $actorName) !== 0) {
                return $candidate;
            }
        }

        return '';
    }

    public static function stripChimContextPrefix($rawData) {
        $data = trim((string)$rawData);

        if ($data === '') {
            return '';
        }

        // CHIM may prepend "(Context location: X)PlayerName:" before the real payload.
        $data = preg_replace('/^\(Context[^)]*\)\s*/u', '', $data);
        $caretPos = strpos($data, '^');
        $colonPos = strpos($data, ':');
        if ($colonPos !== false && ($caretPos === false || $colonPos < $caretPos)) {
            $data = substr($data, $colonPos + 1);
        }

        return trim($data);
    }

    public static function cleanNpcName($name) {
        $name = trim((string)$name);
        $name = preg_replace('/^\(Context[^)]*\)\s*/u', '', $name);
        if (strpos($name, ':') !== false) {
            $name = trim(substr($name, strrpos($name, ':') + 1));
        }
        return trim($name);
    }

    /**
     * Process NPC-to-NPC scene start event
     *
     * NPCs can talk to each other through the LLM. Tier prompts guide their
     * emotional response, while scene control remains owned by OStim NPCs.
     *
     * Returns: [threadID, sceneID, npc1/npc2 info with tier prompts, consent levels]
     */
    public static function processNpcScene($rawData) {
        $rawData = self::stripChimContextPrefix($rawData);

        // Use ^ delimiter to avoid conflict with CHIM's pipe-delimited message format
        $parts = explode('^', $rawData);

        if (count($parts) < 4) {
            error_log("[NSFW NPC Scene] Invalid raw data: " . $rawData);
            return null;
        }

        $npc1Name = self::cleanNpcName($parts[0]);
        $npc2Name = self::cleanNpcName($parts[1]);
        $threadID = intval($parts[2]);
        $sceneID = $parts[3];
        $playerDistance = null;
        $actorPayload = $parts[6] ?? '';
        $rolesPayload = $parts[7] ?? '';
        $enginePartner = '';
        for ($i = 6; $i < count($parts); $i++) {
            $extraPart = trim((string)$parts[$i]);
            if (preg_match('/^(?:dist|distance)=(-?\d+(?:\.\d+)?)$/i', $extraPart, $m)) {
                $playerDistance = (float)$m[1];
            } else if (stripos($extraPart, 'actors=') === 0) {
                $actorPayload = substr($extraPart, 7);
            } else if (stripos($extraPart, 'roles=') === 0) {
                $rolesPayload = substr($extraPart, 6);
            } else if (stripos($extraPart, 'partner=') === 0) {
                // Engine-authoritative partner for the SPEAKING NPC (PSC resolved it from OStim metadata /
                // SexLab positions). Overrides the server's first-other guess so threesomes attribute correctly.
                $enginePartner = self::cleanNpcName(substr($extraPart, 8));
            }
        }

        $actorList = self::cleanActorList(self::decodePayloadJson($actorPayload) ?: [$npc1Name, $npc2Name]);
        if (empty($actorList)) {
            $actorList = [$npc1Name, $npc2Name];
        }
        foreach ([$npc1Name, $npc2Name] as $requiredActor) {
            $found = false;
            foreach ($actorList as $actor) {
                if (strcasecmp($actor, $requiredActor) === 0) {
                    $found = true;
                    break;
                }
            }
            if (!$found && $requiredActor !== '') {
                $actorList[] = $requiredActor;
            }
        }
        $actorRoles = self::decodePayloadJson($rolesPayload) ?: [];

        // The narrator is never a real participant - never let it into an NPC-to-NPC scene/affinity lookup
        if (nsfwIsNarratorName($npc1Name) || nsfwIsNarratorName($npc2Name)) {
            error_log("[NSFW NPC Scene] Narrator present as participant - ignoring scene: " . $rawData);
            return null;
        }

        error_log("[NSFW NPC Scene] Processing: " . implode(' + ', $actorList) . " (Thread: {$threadID})");

        // Get affinity between the NPCs
        $npc1ToNpc2Affinity = self::getNpcToNpcAffinity($npc1Name, $npc2Name);
        $npc2ToNpc1Affinity = self::getNpcToNpcAffinity($npc2Name, $npc1Name);

        // Get consent levels using the 11-tier system (for logging/context)
        $npc1ConsentLevel = NsfwRelationship::getConsentLevel($npc1ToNpc2Affinity);
        $npc2ConsentLevel = NsfwRelationship::getConsentLevel($npc2ToNpc1Affinity);

        error_log("[NSFW NPC Scene] Affinity: {$npc1Name}->{$npc2Name}: {$npc1ToNpc2Affinity} ({$npc1ConsentLevel}), {$npc2Name}->{$npc1Name}: {$npc2ToNpc1Affinity} ({$npc2ConsentLevel})");

        // Get tier prompts for both NPCs - these guide their emotional response
        // The LLM will use these to decide how the NPC behaves (including refusing if hostile)
        $npc1TierPrompt = self::getTierPromptForNpc($npc1Name, $npc2Name, $npc1ToNpc2Affinity);
        $npc2TierPrompt = self::getTierPromptForNpc($npc2Name, $npc1Name, $npc2ToNpc1Affinity);

        $primaryPartners = [];
        foreach ($actorList as $actor) {
            $primaryPartners[$actor] = self::resolvePrimaryPartnerForActor($actor, $actorList, $sceneID);
        }
        // The PSC sends the engine-authoritative partner for the NPC whose TURN this is (the addressed speaker =
        // HERIKA_NAME). Trust it over the guess - this is the threesome attribution fix.
        if ($enginePartner !== '') {
            $speaker = self::cleanNpcName($GLOBALS["HERIKA_NAME"] ?? '');
            if ($speaker !== '') {
                $primaryPartners[$speaker] = $enginePartner;
                error_log("[NSFW NPC Scene] Engine partner override: {$speaker} -> {$enginePartner}");
            }
        }

        // Build scene context message
        $sceneContext = self::buildSceneContext($npc1Name, $npc2Name, $sceneID, $actorList, $actorRoles);

        return [
            'threadID' => $threadID,
            'sceneID' => $sceneID,
            'actors' => $actorList,
            'actorRoles' => $actorRoles,
            'primaryPartners' => $primaryPartners,
            'playerDistance' => $playerDistance,
            'npc1' => [
                'name' => $npc1Name,
                'affinity' => $npc1ToNpc2Affinity,
                'consentLevel' => $npc1ConsentLevel,
                'tierPrompt' => $npc1TierPrompt,
                'isChimEnabled' => self::isChimEnabledNpc($npc1Name)
            ],
            'npc2' => [
                'name' => $npc2Name,
                'affinity' => $npc2ToNpc1Affinity,
                'consentLevel' => $npc2ConsentLevel,
                'tierPrompt' => $npc2TierPrompt,
                'isChimEnabled' => self::isChimEnabledNpc($npc2Name)
            ],
            'sceneContext' => $sceneContext
        ];
    }

    /**
     * Get affinity from NPC1 toward NPC2
     * Uses RelationshipManager's NPC-to-NPC relationship if available
     */
    private static function getNpcToNpcAffinity($fromNpc, $toNpc) {
        // REL system is the source of truth. Papyrus relationship ranks are diagnostics only,
        // and player-affinity proxy guesses must not decide NPC-to-NPC consent.
        if (class_exists('RelationshipManager')) {
            if (method_exists('RelationshipManager', 'getRelationship')) {
                $rel = RelationshipManager::getRelationship($fromNpc, $toNpc);
                if ($rel && isset($rel['aff'])) {
                    return intval($rel['aff']);
                }
            }

            // Check if there's an NPC-to-NPC relationship method
            if (method_exists('RelationshipManager', 'getNpcRelationship')) {
                $rel = RelationshipManager::getNpcRelationship($fromNpc, $toNpc);
                if ($rel && isset($rel['aff'])) {
                    return intval($rel['aff']);
                }
            }
        }

        // Default: Neutral if REL has no explicit edge yet.
        return 0;
    }

    /**
     * Get tier prompt for an NPC based on their affinity toward partner
     * Includes affair detection - if NPC is married and partner != spouse, uses affair prompts
     */
    private static function getTierPromptForNpc($npcName, $partnerName, $affinity) {
        if (isNpcSlave($npcName)) {
            return NsfwRelationship::getSlaveTierPrompt($affinity, $partnerName); // slaves get the slave tier prompt in NPC scenes too
        }
        $isProstitute = self::isProstitute($npcName);
        return NsfwRelationship::getTierPromptByAffinity($affinity, $isProstitute, $partnerName, $npcName, 'npc');
    }

    /**
     * Check if NPC is a CHIM-enabled NPC (has an entry in our system)
     */
    private static function isChimEnabledNpc($npcName) {
        if (isset($GLOBALS["db"])) {
            try {
                $escapedName = $GLOBALS["db"]->escape($npcName);
                $row = $GLOBALS["db"]->fetchOne(
                    "SELECT id FROM core_npc_master WHERE LOWER(npc_name) = LOWER('{$escapedName}')"
                );
                return !empty($row);
            } catch (Exception $e) {
                // Silently fail
            }
        }
        return false;
    }

    /**
     * Check if NPC is marked as prostitute
     * Delegates to global isProstitute() from common.php
     */
    private static function isProstitute($npcName) {
        return isProstitute($npcName);
    }

    /**
     * Build scene context message for NPCs
     */
    private static function buildSceneContext($npc1Name, $npc2Name, $sceneID, $actorList = [], $actorRoles = []) {
        $actorList = self::cleanActorList($actorList);
        if (empty($actorList)) {
            $actorList = [$npc1Name, $npc2Name];
        }
        $sceneDesc = "";
        if (!empty($sceneID) && function_exists('getSceneDescription')) {
            // The roles OStim reports per actor (dom, vaginal, cowgirl...) are tags too: a scene the import lacks is described
            // from them (LOCAL, branch reldyn-scenes)
            $roleTags = [];
            foreach ((array)$actorRoles as $roles) {
                foreach ((array)$roles as $role) { $roleTags[] = strtolower(trim((string)$role)); }
            }
            $sceneDesc = trim((string)getSceneDescription($sceneID, $actorList, array_values(array_unique($roleTags)), false));
        }
        if ($sceneDesc === '' && !empty($sceneID)) {
            // OStim scene IDs often contain readable tags when no lookup exists yet.
            $sceneDesc = str_replace(['_', '|'], ' ', $sceneID);
        }
        if ($sceneDesc === '') {
            $sceneDesc = implode(' and ', $actorList) . " are having an intimate moment";
        }
        $participantText = implode(', ', $actorList);

        return "<NPC_SCENE_CONTEXT>
NPC-to-NPC scene participants: {$participantText}.
Current scene: {$sceneDesc}
The player is not directly involved.
</NPC_SCENE_CONTEXT>";
    }

    /**
     * Queue dialogue for NPC based on their tier prompt
     * This adds the appropriate tier-based response instruction
     */
    public static function queueNpcDialogue($npcName, $tierPrompt, $sceneContext) {
        if (empty($tierPrompt)) {
            return false;
        }

        // Format the complete message with tier prompt and context
        $message = $sceneContext . "\n" . $tierPrompt;

        // Queue this for the NPC through CHIM's message system
        // This will make the NPC respond with their tier-appropriate reaction
        if (function_exists('AIAgentFunctions') || class_exists('AIAgentFunctions')) {
            // Use the existing CHIM API if available
            // This would need to be called from the router
            return true;
        }

        return false;
    }
}
?>
