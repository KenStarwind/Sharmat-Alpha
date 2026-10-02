<?php
/**
 * OStim Scene Handler
 *
 * Handles all OStim/SexLab scene processing:
 * - Scene start/update/end events
 * - Climax events and speech generation
 * - Sex speech styles and prompts
 * - GASP integration for orgasm sounds
 *
 * This is the high-churn file for LLM personality tweaking during scenes.
 *
 * USAGE:
 *   NsfwOstimHandler::processEvent()           - Main entry point (handles all scene events)
 *   NsfwOstimHandler::setSexSpeechStyle($name) - Inject speech style for actor
 *   NsfwOstimHandler::setSexPrompt($name)      - Inject sex personality prompt
 *   NsfwOstimHandler::generateClimaxSpeech()   - Generate orgasm speech via LLM
 */
require_once __DIR__ . "/scene_threads.php";
require_once __DIR__ . "/scene_role_policy.php";

class NsfwOstimHandler {
    private static function suppressHistoricContextForCurrentRequest($reason) {
        $existingFilter = trim((string)($GLOBALS["EXT_CONTEXT_SQL_FILTER1"] ?? ""));
        if (strpos($existingFilter, "AIAGENTNSFW_STANDING_FAST_PATH") === false) {
            $suffix = "and 1=0 /* AIAGENTNSFW_STANDING_FAST_PATH: " . preg_replace('/[^a-zA-Z0-9 _-]/', '', (string)$reason) . " */";
            $GLOBALS["EXT_CONTEXT_SQL_FILTER1"] = trim($existingFilter . " " . $suffix);
        }
        $GLOBALS["AIAGENTNSFW_SUPPRESS_HISTORIC_CONTEXT"] = true;
        error_log("[AIAGENTNSFW] Suppressing historic context for current standing/intro request: " . $reason);
    }

    // Has real sex ALREADY happened in this scene thread? had_sex_in_scene survives the tier<=2
    // de-escalation clears (sex_started is deliberately dropped on breather beats) and resets on
    // every new scene start - so it is the honest "we are between acts, not before them" signal.
    private static function sceneSexAlreadyUnderway($orderedActorList) {
        foreach ((array)$orderedActorList as $suActor) {
            if ($suActor === ($GLOBALS["PLAYER_NAME"] ?? '')) { continue; }
            $suIx = getIntimacyForActor($suActor);
            if (!empty($suIx['sex_started']) || !empty($suIx['had_sex_in_scene'])) { return true; }
        }
        return false;
    }

    private static function sceneUnpaidOrRefusedProstituteName($orderedActorList) {
        if (!is_array($orderedActorList) || empty($orderedActorList)) {
            return "";
        }

        $playerName = strtolower((string)($GLOBALS["PLAYER_NAME"] ?? ""));
        foreach ($orderedActorList as $actorName) {
            $actorName = trim((string)$actorName);
            if ($actorName === "" || strtolower($actorName) === $playerName) {
                continue;
            }
            if (!function_exists('isProstitute') || !isProstitute($actorName)) {
                continue;
            }

            $intimacyStatus = function_exists('getIntimacyForActor') ? getIntimacyForActor($actorName) : [];
            $isTransaction = !empty($intimacyStatus["is_transaction"]) || !empty($intimacyStatus["negotiation_phase"]);
            $isUnpaid = $isTransaction && empty($intimacyStatus["payment_confirmed"]);
            $isRefused = (($intimacyStatus["scene_phase"] ?? null) === "rejected")
                || !empty($intimacyStatus["refusal_expressed"])
                || !empty($intimacyStatus["request_scene_stop"]);

            if ($isUnpaid || ($isRefused && empty($intimacyStatus["payment_confirmed"]))) {
                return $actorName;
            }
        }

        return "";
    }

    /**
     * Main entry point - Process all OStim scene events
     * Handles: ext_nsfw_sexcene, chatnf_sl_end, chatnf_sl_naked,
     *          chatnf_sl_climax, chatnf_sl_moan, ext_nsfw_action,
     *          ext_nsfw_scene, ext_nsfw_orgasm, ext_nsfw_npc_scene
     */
    public static function processEvent() {
        global $gameRequest;

        $fwEvent = $gameRequest[0] ?? '';
        $fwData = $gameRequest[3] ?? '';
        if (function_exists('aiagentNsfwSceneFrameworkAllowed') && !aiagentNsfwSceneFrameworkAllowed($fwEvent, $fwData)) {
            error_log("[AIAGENTNSFW] Blocked {$fwEvent} by scene-framework detect toggle (handler)");
            $gameRequest[0] = "nsfw_blocked_policy";
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_policy";
            return;
        }

        // Track if this is The Narrator profile. Scene state updates (handleSceneUpdate)
        // MUST still run for Narrator — Papyrus routes scene events through The Narrator,
        // and handleSceneUpdate() updates intimacy data for the ACTUAL scene actors
        // (from OStim event data, not from HERIKA_NAME). But The Narrator must never
        // generate scene dialogue — FORCE_STOP handles that inside handleSceneUpdate().
        $herikaName = $GLOBALS["HERIKA_NAME"] ?? '';
        $isNarratorProfile = ($herikaName === "The Narrator" || $herikaName === "Character");
        $GLOBALS["AIAGENTNSFW_IS_NARRATOR_PROFILE"] = $isNarratorProfile;

        // HEARTBEAT for the stale-PLAYER-scene safety net. Any live scene-runtime event proves the scene is
        // still going, so refresh last_scene_update_time. handleSceneUpdate stamps on position/stage changes,
        // but orgasm/climax/naked/action fire BETWEEN those - without this a held OStim position or a SexLab
        // stage with auto-advance off could look stale and get torn down mid-scene. NOT chatnf_sl_end (the end).
        $liveSceneEvents = ['ext_nsfw_orgasm', 'chatnf_sl_climax', 'chatnf_sl_moan', 'chatnf_sl_naked', 'ext_nsfw_action', 'ext_nsfw_scene'];
        if (!$isNarratorProfile && $herikaName !== '' && in_array($gameRequest[0] ?? '', $liveSceneEvents, true)) {
            $hb = getIntimacyForActor($herikaName);
            if (is_array($hb) && empty($hb['is_npc_scene'])
                && (!empty($hb['sex_started']) || (int)($hb['level'] ?? 0) > 0 || !empty($hb['current_scene_desc'])
                    || in_array(strtolower(trim((string)($hb['scene_phase'] ?? ''))), ['tier_prompt', 'accepted', 'engaged'], true))) {
                $hb['last_scene_update_time'] = time();
                updateIntimacyForActor($herikaName, $hb);
            }
        }

        if ($gameRequest[0] == "ext_nsfw_sexcene") {
            self::handleSceneUpdate();
        } else if ($gameRequest[0] == "ext_nsfw_npc_scene") {
            self::handleNpcScene();
        } else if ($gameRequest[0] == "chatnf_sl_end") {
            self::handleSceneEnd();
        } else if ($gameRequest[0] == "chatnf_sl_naked") {
            self::handleNaked();
        } else if ($gameRequest[0] == "chatnf_sl_climax") {
            self::handleClimax();
        } else if ($gameRequest[0] == "chatnf_sl_moan") {
            self::handleMoan();
        } else if ($gameRequest[0] == "ext_nsfw_action") {
            self::handleAction();
        } else if ($gameRequest[0] == "ext_nsfw_scene") {
            self::handleSceneEvent();
        } else if ($gameRequest[0] == "ext_nsfw_orgasm") {
            self::handleOrgasm();
        } else if ($gameRequest[0] == "ext_nsfw_npc_invite") {
            self::handleNpcInvite();
        } else if ($gameRequest[0] == "ext_nsfw_npc_orgasm") {
            self::handleNpcOrgasm();
        } else if ($gameRequest[0] == "ext_nsfw_payment_check") {
            self::handlePaymentCheck();
        }
    }

    /**
     * ext_nsfw_payment_check@<receivedValue>@<goldReceived>
     * Auto-fired by the game (DLL/Papyrus) right after the player gives the prostitute an item or
     * the trade box closes. The game sends ONLY raw gold-equivalent values; the SERVER decides the
     * outcome vs the agreed price (Option A - all logic server-side). Once confirmed she is paid for
     * the whole session (the nonpayment guard stops firing) until time runs out or the player orgasms.
     */
    private static function handlePaymentCheck() {
        global $gameRequest;
        $npcName = $GLOBALS["HERIKA_NAME"] ?? '';
        if ($npcName === '' || (function_exists('isProstitute') && !isProstitute($npcName))) {
            return;
        }
        $intimacyStatus = getIntimacyForActor($npcName);

        // Be lenient about the exact param shape - pull the numeric values out of whatever the game sent.
        $parts = explode("@", (string)($gameRequest[3] ?? ''));
        $nums = array_values(array_filter(array_map('trim', $parts), function ($p) { return $p !== '' && is_numeric($p); }));
        $receivedValue = isset($nums[0]) ? (int)$nums[0] : 0;
        $goldReceived  = isset($nums[1]) ? (int)$nums[1] : 0;

        // Item identity: the game now sends value@gold@count@name so she can name the EXACT item she got.
        // Older builds send only value@gold (no name) - handled gracefully.
        $itemCount = 0;
        $itemName = '';
        if (isset($parts[2])) {
            if (is_numeric(trim($parts[2]))) {
                $itemCount = (int)trim($parts[2]);
                if (count($parts) > 3) { $itemName = trim(implode('@', array_slice($parts, 3))); }
            } else {
                $itemName = trim(implode('@', array_slice($parts, 2)));
            }
        }
        // Gold gives no name from the game; label it ourselves so she still names it.
        if ($itemName === '' && $goldReceived > 0 && $goldReceived === $receivedValue) { $itemName = 'gold'; }
        $itemLabel = $itemName !== '' ? (($itemCount > 1 ? "{$itemCount}x " : "") . $itemName) : '';

        // Agreed price: what was negotiated this session, else her configured flat rate.
        require_once __DIR__ . '/nsfw_data.php';
        $extended = NsfwNpcData::get($npcName);
        $configuredPrice = (int)($extended['prostitute_price'] ?? ($extended['prostitute_pricing']['flat_price'] ?? 0));
        // Apply the affinity-tier discount/premium so the gate expects the SAME amount she quoted.
        if ($configuredPrice > 0 && function_exists('aiagentNsfwProstituteAffinityPrice')) {
            $configuredPrice = aiagentNsfwProstituteAffinityPrice($configuredPrice, getNpcAffinity($npcName));
        }
        $agreedPrice = (int)($intimacyStatus['payment_pending_amount'] ?? 0);
        if ($agreedPrice <= 0) { $agreedPrice = $configuredPrice; }
        if ($agreedPrice <= 0) { $agreedPrice = 1; } // no price on record -> any payment of value counts

        error_log("[PaymentCheck] {$npcName}: received={$receivedValue} gold={$goldReceived} agreedPrice={$agreedPrice} raw=" . ($gameRequest[3] ?? ''));

        // Accumulate received value across the transaction - gold and items can arrive as SEPARATE
        // OnItemAdded events (e.g. "100 gold + fire salts"), so judge the running TOTAL, not each add.
        $now = time();
        $prevTotal = (int)($intimacyStatus['payment_received_total'] ?? 0);
        $prevGold  = (int)($intimacyStatus['payment_gold_total'] ?? 0);
        $lastTime  = (int)($intimacyStatus['payment_received_last_time'] ?? 0);
        if ($lastTime > 0 && ($now - $lastTime) > 600) { $prevTotal = 0; $prevGold = 0; } // stale/abandoned attempt -> reset tally
        $total = $prevTotal + max(0, $receivedValue);
        $goldTotal = $prevGold + max(0, $goldReceived); // track gold separately so we can tell gold vs barter at confirm time
        $itemTotal = max(0, $total - $goldTotal);
        $intimacyStatus['payment_received_total'] = $total;
        $intimacyStatus['payment_gold_total'] = $goldTotal;
        $intimacyStatus['payment_received_last_time'] = $now;
        error_log("[PaymentCheck] {$npcName}: thisAdd={$receivedValue} runningTotal={$total} gold={$goldTotal} item={$itemTotal} agreedPrice={$agreedPrice}");

        // Pull the UI-editable outcome prompts (Prostitution Global tab); fall back to built-in defaults.
        $payer = $GLOBALS['PLAYER_NAME'] ?? 'the client';
        $remaining = max(0, $agreedPrice - $total);
        $itemForPrompt = $itemLabel !== '' ? $itemLabel : 'what they gave';
        $fillPaymentPrompt = function ($key, $default) use ($payer, $total, $agreedPrice, $remaining, $itemForPrompt) {
            $tpl = (function_exists('getGlobalPrompt') ? getGlobalPrompt($key) : '') ?: $default;
            return str_replace(
                ['#PLAYER_NAME#', '#AMOUNT#', '#PRICE#', '#REMAINING#', '#ITEM#'],
                [$payer, $total, $agreedPrice, $remaining, $itemForPrompt],
                $tpl
            );
        };

        if ($total >= $agreedPrice) {
            // Satisfied (gold, item, or a mix) -> confirm, accept, lock for the session; reset the tally.
            $intimacyStatus['payment_confirmed'] = true;
            $intimacyStatus['payment_confirmed_amount'] = $total;
            $intimacyStatus['payment_confirmed_time'] = time();
            $intimacyStatus['payment_failed'] = false;
            $intimacyStatus['payment_failure_reason'] = null;
            $intimacyStatus['payment_pending'] = false;
            $intimacyStatus['payment_pending_item'] = null;
            $intimacyStatus['payment_received_total'] = 0;
            $intimacyStatus['payment_gold_total'] = 0;
            $intimacyStatus['negotiation_phase'] = false;
            $intimacyStatus['ready_for_service'] = true;
            $intimacyStatus['scene_phase'] = "accepted";
            $intimacyStatus['accepted_sex'] = true;
            $intimacyStatus['refusal_expressed'] = false;
            $intimacyStatus['request_scene_stop'] = false;
            // Persist the confirmation to the durable ledger so a stale intimacy write can't
            // clobber it (it survives until player orgasm or the payment window expires).
            if (function_exists('aiagentNsfwSetPaymentLedger')) {
                aiagentNsfwSetPaymentLedger($npcName, $total, $agreedPrice, $goldTotal);
            }
            // Distinguish gold-only vs barter so the right UI prompt fires.
            if ($itemTotal > 0) {
                $msg = $fillPaymentPrompt('payment_satisfied_item', "#PLAYER_NAME# has handed you goods worth #AMOUNT#, which covers your agreed price of #PRICE#. The barter is settled - the payment IS your agreement. Proceed with the service and do not ask for payment again this session.");
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n<payment_received>{$msg}</payment_received>";
                error_log("[PaymentCheck] CONFIRMED (item/barter) for {$npcName} (total {$total} >= {$agreedPrice})");
            } else {
                $msg = $fillPaymentPrompt('payment_satisfied_gold', "You have received #AMOUNT# gold from #PLAYER_NAME#, which covers your agreed price of #PRICE#. Payment is settled - the payment IS your agreement. Proceed with the service and do not ask for payment again this session.");
                $GLOBALS["HERIKA_PERSONALITY"] .= "\n<payment_received>{$msg}</payment_received>";
                error_log("[PaymentCheck] CONFIRMED (gold) for {$npcName} (total {$total} >= {$agreedPrice})");
            }
        } else if ($total > 0) {
            // Partial: something given but the running total still falls short -> ask for the rest or to take it back.
            $intimacyStatus['payment_confirmed'] = false;
            $intimacyStatus['payment_failed'] = false; // not a hard fail - still topping up / negotiating
            $intimacyStatus['payment_failure_reason'] = "partial: {$total} of {$agreedPrice}";
            $intimacyStatus['payment_pending'] = false;
            $intimacyStatus['negotiation_phase'] = true;
            $intimacyStatus['ready_for_service'] = false;
            $msg = $fillPaymentPrompt('payment_insufficient', "So far #PLAYER_NAME# has given payment worth #AMOUNT#, but your price is #PRICE# - still #REMAINING# short. Tell them it is not enough yet: ask for the remaining #REMAINING# (gold or goods), or hand back what they gave (use GiveItemTo to return it). Do not provide the service until the full price is met.");
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n<payment_insufficient>{$msg}</payment_insufficient>";
            error_log("[PaymentCheck] PARTIAL for {$npcName} ({$total}/{$agreedPrice})");
        } else {
            // Nothing of value received.
            $intimacyStatus['payment_confirmed'] = false;
            $intimacyStatus['negotiation_phase'] = true;
            $intimacyStatus['ready_for_service'] = false;
            $msg = $fillPaymentPrompt('payment_none', "#PLAYER_NAME# gave you nothing of value. No payment has been made. Hold to your price and do not provide the service.");
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n<payment_none>{$msg}</payment_none>";
            error_log("[PaymentCheck] NONE for {$npcName}");
        }

        // Make her REACT IMMEDIATELY this turn - the payment event is otherwise silent (empty cue), which
        // is why she never acknowledged the item. Name the exact item + value so she adjusts on the spot,
        // the moment the trade menu closes.
        $itemDesc = $itemLabel !== '' ? "{$itemLabel} (worth {$receivedValue} gold)" : "{$receivedValue} gold";
        if ($total >= $agreedPrice) {
            $reactClause = "That covers your price of {$agreedPrice}. The payment is your agreement - proceed with the service.";
        } else if ($total > 0) {
            $reactClause = "That is only worth {$total} of your {$agreedPrice} price - still {$remaining} short. Name what they gave, tell them plainly it is not enough, and ask for the rest or hand it back.";
        } else {
            $reactClause = "That is worthless as payment toward your {$agreedPrice} price. Tell them so and hold your price.";
        }
        $GLOBALS["HERIKA_NAME"] = $npcName; // SHE reacts, not the narrator
        // Store the cue in a global; prompts.php registers $PROMPTS["ext_nsfw_payment_check"] from it.
        // (prompts.php RESETS $PROMPTS after prerequest, so a direct set here gets wiped - same reason
        // the orgasm cue is passed via AIAGENTNSFW_ORGASM_CUE_OVERRIDE.)
        $GLOBALS["AIAGENTNSFW_PAYMENT_CUE"] = "Scene Event: {$payer} just handed {$npcName} {$itemDesc} as payment. {$reactClause} React out loud right now, in character - acknowledge the item by name.";
        $GLOBALS["AVOID_LLM_CALL"] = false; // ensure the reaction is actually generated

        updateIntimacyForActor($npcName, $intimacyStatus);
        $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = false; // she should speak her reaction this turn
        logEvent($GLOBALS["gameRequest"]);
    }

    /**
     * Handle ext_nsfw_sexcene - Main scene update event
     * Parse info_sexscene data and manage scene phases
     */
    private static function handleSceneUpdate() {
        global $gameRequest;

        // Parse info_sexscene data
        // Format: SceneName/Tags/StageName/Actor1/Actor2/...
        $infoSexSceneParts = explode("/", $gameRequest[3]);
        $sexSceneName      = $infoSexSceneParts[0];
        $sexTags           = explode(",", strtolower($infoSexSceneParts[1]));
        $sexStageName      = strtr($infoSexSceneParts[2], ["_A1" => ""]);
        $actorInfosRaw     = array_slice($infoSexSceneParts, 3);

        // Parse actor data — new format includes per-actor role tags:
        //   "ActorName^tag1,tag2,tag3" (e.g. "Whiterun Guard^dom,vaginal")
        // Old format without tags: just "ActorName" (backwards compatible)
        $playerName = $GLOBALS["PLAYER_NAME"];
        $rawActorList = [];
        $orderedActorList = [];
        $otherActors = [];
        $actorRoles = [];  // Maps actor name => array of OStim role tags
        $scenePlayerDistance = null;

        foreach ($actorInfosRaw as $actorinfo) {
            if (empty($actorinfo)) {
                continue;
            }
            // Split name from tags / metadata (caret separator)
            $actorParts = explode('^', $actorinfo);
            $actorName = trim($actorParts[0]);
            $actorTags = [];
            foreach (array_slice($actorParts, 1) as $actorMeta) {
                $actorMeta = trim((string)$actorMeta);
                if ($actorMeta === '') {
                    continue;
                }
                if (preg_match('/^(?:dist|distance)=(-?\d+(?:\.\d+)?)$/i', $actorMeta, $m)) {
                    $actorDistance = (float)$m[1];
                    if ($scenePlayerDistance === null || $actorDistance < $scenePlayerDistance) {
                        $scenePlayerDistance = $actorDistance;
                    }
                    continue;
                }
                foreach (explode(',', strtolower($actorMeta)) as $tag) {
                    $tag = trim($tag);
                    if ($tag !== '') {
                        $actorTags[] = $tag;
                    }
                }
            }

            if (empty($actorName)) continue;
            if (nsfwIsNarratorName($actorName)) continue; // the narrator is never a scene actor/partner

            $rawActorList[] = $actorName;
            $actorRoles[$actorName] = $actorTags;

            if ($actorName === $playerName) {
                array_unshift($orderedActorList, $actorName);
            } else {
                $otherActors[] = $actorName;
            }
        }

        // Append non-player actors in their original relative order
        $orderedActorList = array_merge($orderedActorList, $otherActors);

        $playerInScene = false;
        foreach ($orderedActorList as $sceneActor) {
            if (strcasecmp($sceneActor, $playerName) === 0) {
                $playerInScene = true;
                break;
            }
        }
	        if (!$playerInScene && count($orderedActorList) >= 2) {
	            $syntheticThreadID = abs(crc32(strtolower($sexSceneName . '|' . $sexStageName . '|' . implode('|', $orderedActorList))));
            $npcSceneID = trim((string)$sexStageName);
            if ($npcSceneID === '') {
                $npcSceneID = trim((string)$sexSceneName);
            }
            $npcSceneActorsPayload = base64_encode(json_encode($orderedActorList, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $npcSceneRolesPayload = base64_encode(json_encode($actorRoles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $gameRequest[0] = "ext_nsfw_npc_scene";
            $npcScenePayload = $orderedActorList[0] . "^" . $orderedActorList[1] . "^" . $syntheticThreadID . "^" . $npcSceneID . "^0^0^actors=" . $npcSceneActorsPayload . "^roles=" . $npcSceneRolesPayload;
            if ($scenePlayerDistance !== null) {
                $npcScenePayload .= "^dist=" . round($scenePlayerDistance, 2);
            }
            $gameRequest[3] = $npcScenePayload;
            error_log("[AIAGENTNSFW] Canonicalized playerless ext_nsfw_sexcene to NPC scene route: {$gameRequest[3]}");
	            self::handleNpcScene();
	            return;
	        }
	        $sceneThreadKey = aiagentNsfwSceneThreadKey('player_scene', $orderedActorList);
	        $GLOBALS["AIAGENTNSFW_SCENE_THREAD_KEY"] = $sceneThreadKey;
	
	        // Determine primary partner from OStim role tags.
        // In group scenes, the partner interacting with the player has active sexual tags
        // (dom, sub, vaginal, anal, oral, etc). Observers/watchers have passive tags or none.
        $activeSexTags = ['dom', 'sub', 'aggressor', 'victim', 'forced', 'vaginal', 'anal', 'oral', 'handjob', 'blowjob',
                          'cunnilingus', 'penetration', 'riding', 'missionary', 'doggystyle',
                          'cowgirl', 'reversecowgirl', 'prone', 'standing'];
        $primaryPartner = null;
        foreach ($otherActors as $candidate) {
            $candidateTags = $actorRoles[$candidate] ?? [];
            foreach ($candidateTags as $tag) {
                if (in_array($tag, $activeSexTags)) {
                    $primaryPartner = $candidate;
                    break 2;
                }
            }
        }
        // Fallback: first non-player actor if no active tags found
        if (!$primaryPartner && !empty($otherActors)) {
            $primaryPartner = $otherActors[0];
        }

        // Store actor list and roles globally
        $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] = $orderedActorList;
        $GLOBALS["AIAGENTNSFW_RAW_SCENE_ACTOR_SLOTS"] = $rawActorList;
        $GLOBALS["AIAGENTNSFW_ACTOR_ROLES"] = $actorRoles;

        $frameworkPlayerVictim = aiagentNsfwFrameworkPlayerIsVictim($actorRoles, $playerName);

        // Keep one short-lived diagnostic snapshot for the Defeat tab. Forced-role tags are
        // emitted by the SexLab hook, so this records what the game actually reported instead
        // of inferring defeat from animation names or tags.
        $frameworkVictims = [];
        $frameworkAggressors = [];
        foreach ($orderedActorList as $frameworkActorName) {
            if (!aiagentNsfwActorHasSceneRole($actorRoles, $frameworkActorName, 'forced')) {
                continue;
            }
            if (aiagentNsfwActorHasSceneRole($actorRoles, $frameworkActorName, 'victim')) {
                $frameworkVictims[] = $frameworkActorName;
            } elseif (aiagentNsfwActorHasSceneRole($actorRoles, $frameworkActorName, 'aggressor')) {
                $frameworkAggressors[] = $frameworkActorName;
            }
        }
        if ((!empty($frameworkVictims) || !empty($frameworkAggressors)) && function_exists('aiagentNsfwRuntimeStateSet')) {
            aiagentNsfwRuntimeStateSet('defeat_diagnostics', 'last_forced_scene', [
                'detected_at' => time(),
                'source' => 'SexLab victim metadata',
                'event' => (string)($GLOBALS['gameRequest'][0] ?? 'ext_nsfw_sexcene'),
                'scene_name' => (string)$sexSceneName,
                'stage' => (string)$sexStageName,
                'player' => (string)$playerName,
                'player_is_victim' => $frameworkPlayerVictim,
                'victims' => array_values($frameworkVictims),
                'aggressors' => array_values($frameworkAggressors),
                'bypass_activated' => $frameworkPlayerVictim && !empty($frameworkAggressors),
                'roles' => $actorRoles,
            ], 604800);
        }

        error_log("[AIAGENTNSFW] Scene actors: " . implode(", ", $orderedActorList) .
                  " | Primary partner: $primaryPartner" .
                  " | Roles: " . json_encode($actorRoles));

        // ============================================
        // IMMEDIATE LOG WRITE — before any complex processing
        // ============================================
        // This MUST happen first so scene events show in CHIM log
        // regardless of what crashes later in intimacy/tier/description code.
        // ============================================
        $partnerNamesEarly = array_filter($orderedActorList, function($a) { return $a !== $GLOBALS["PLAYER_NAME"]; });
        $partnerStrEarly = ($primaryPartner && count($partnerNamesEarly) > 1) ? "$primaryPartner (and " . implode(", ", array_filter($partnerNamesEarly, function($a) use ($primaryPartner) { return $a !== $primaryPartner; })) . ")" : implode(" and ", $partnerNamesEarly);
        try {
            $earlyLogData = $GLOBALS["gameRequest"];
            $earlyLogData[0] = "info";
            $earlyLogData[3] = "[SCENE] with $partnerStrEarly | Stage: $sexStageName | Tags: " . implode(",", $sexTags);
            logEvent($earlyLogData);
            error_log("[AIAGENTNSFW] SCENE LOGGED: $partnerStrEarly | $sexStageName | " . implode(",", $sexTags));
        } catch (Exception $e) {
            error_log("[AIAGENTNSFW] ERROR writing scene log: " . $e->getMessage());
        }

        // ============================================
        // Scene triggers tier prompt FIRST
        // ============================================
        // Level meanings:
        //   0 = Not in scene OR tier prompt phase (accept/refuse)
        //   1 = Accepted, pre-intimate (foreplay, arousal building)
        //   2 = Scene engaged (full intimate, styles injected)
        //
        // scene_phase tracks where we are in the flow:
        //   "tier_prompt" = Waiting for model to accept/refuse
        //   "accepted"    = Model accepted, checking arousal
        //   "engaged"     = Full scene, styles active
        // ============================================

        $sceneTier = function_exists('nsfwSceneIntensityTier') ? nsfwSceneIntensityTier($sexTags, trim($sexSceneName . ' ' . $sexStageName), $sexStageName) : 3;
        $isTier0IntroScene = ($sceneTier === 0) && (in_array("idle", $sexTags, true) || in_array("intro", $sexTags, true));
        $isStandingTier0IntroScene = $isTier0IntroScene && !empty($orderedActorList);
        if ($isStandingTier0IntroScene) {
            foreach ($orderedActorList as $standingActor) {
                $standingRoles = $actorRoles[$standingActor] ?? [];
                if (!in_array("standing", $standingRoles, true)) {
                    $isStandingTier0IntroScene = false;
                    break;
                }
            }
        }

        // Server-side belt-and-suspenders dedup. The preprocessing hash catches most repeats, but
        // OStim can submit the same scene payload twice close enough that both requests enter PHP.
        // Exact payload duplicates never carry new relationship/consent information, so suppress only
        // the second model turn while leaving distinct scene stages untouched.
        $sceneProfileKey = preg_replace('/[^a-f0-9]/i', '', (string)($_GET["profile"] ?? ($primaryPartner ? md5($primaryPartner) : "scene")));
        if ($sceneProfileKey !== '') {
            $sceneDedupFile = sys_get_temp_dir() . "/nsfw_scene_handler_last_" . $sceneProfileKey . ".json";
            $sceneDedupHash = md5((string)($gameRequest[3] ?? ''));
            $sceneDedupEventTs = (string)($GLOBALS["gameRequest"][2] ?? '');
            $sceneDedupPrev = @json_decode((string)@file_get_contents($sceneDedupFile), true);
            $sceneDedupTime = (int)($sceneDedupPrev["time"] ?? 0);
            $sceneDedupLast = (string)($sceneDedupPrev["hash"] ?? "");
            $sceneDedupPrevEventTs = (string)($sceneDedupPrev["event_ts"] ?? "");
            $sameSceneDedupEvent = $sceneDedupEventTs !== '' && $sceneDedupPrevEventTs === $sceneDedupEventTs;
            if ($sceneDedupLast === $sceneDedupHash && ($sameSceneDedupEvent || (time() - $sceneDedupTime) <= 2)) {
                $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
                $GLOBALS["gameRequest"][0] = "info";
                $GLOBALS["gameRequest"][3] = "[NSFW] Duplicate OStim scene payload suppressed.";
                error_log("[AIAGENTNSFW] Suppressed duplicate OStim scene payload for profile {$sceneProfileKey}");
                return;
            }
            @file_put_contents($sceneDedupFile, json_encode(["hash" => $sceneDedupHash, "event_ts" => $sceneDedupEventTs, "time" => time()]));
        }

        // Affection transitions like OARE_GoToStandingHandHolding are staging beats. They should update
        // scene state, but the NPC should speak on the settled affection scene, not both the GoTo and final
        // stage. This is intentionally limited to non-sex tiers so actual sex transitions still speak/gate.
        $isAffectionTransitionBeat = ($sceneTier > 0 && $sceneTier <= 2)
            && (in_array("transition", $sexTags, true) || preg_match('/(^|[_ -])go\s*to/i', trim($sexSceneName . ' ' . $sexStageName)) === 1);

	        foreach ($orderedActorList as $actor) {
	            $intimacyStatus = getIntimacyForActor($actor);
	            $frameworkActorAggressor = aiagentNsfwFrameworkActorIsAggressor($actorRoles, $actor, $playerName);
	            $intimacyStatus["framework_forced_scene"] = $frameworkPlayerVictim;
	            $intimacyStatus["scene_player_is_victim"] = $frameworkPlayerVictim;
	            $intimacyStatus["scene_actor_is_aggressor"] = $frameworkActorAggressor;
	            $intimacyStatus["scene_actor_is_victim"] = aiagentNsfwActorHasSceneRole($actorRoles, $actor, 'victim');
	            $priorAcceptedSex = !empty($intimacyStatus["accepted_sex"]);
	            $priorAcceptTime = (int)($intimacyStatus["last_accept_sex_time"] ?? 0);
	            $priorAcceptPartner = (string)($intimacyStatus["last_accept_sex_partner"] ?? ($intimacyStatus["sex_partner"] ?? ""));
	            $refusalLatched = (($intimacyStatus["scene_phase"] ?? null) === "rejected")
	                || !empty($intimacyStatus["refusal_expressed"])
	                || !empty($intimacyStatus["request_scene_stop"]);
	            $existingSceneActors = is_array($intimacyStatus["scene_actors"] ?? null) ? $intimacyStatus["scene_actors"] : [];
	            $existingActorKey = array_map('strtolower', array_map('trim', $existingSceneActors));
	            $currentActorKey = array_map('strtolower', array_map('trim', $orderedActorList));
	            sort($existingActorKey, SORT_STRING);
	            sort($currentActorKey, SORT_STRING);
	            $sameSceneActors = !empty($existingActorKey) && $existingActorKey === $currentActorKey;

	            // Check if this is a NEW scene or continuation
            // A scene is NEW if:
            // 1. No scene_phase set at all (never been in a scene)
            // 2. scene_phase is null (previous scene ended and was cleared)
            // 3. scene_start_time is old (previous scene was >5 min ago, stale data)
            $isNewScene = !isset($intimacyStatus["scene_phase"]) || $intimacyStatus["scene_phase"] === null;

            // Also check for stale scene data - if last scene was >5 minutes ago, treat as new
            if (!$isNewScene && isset($intimacyStatus["scene_start_time"])) {
                // FRESHNESS (fix 2026-07-01e): measure from last ACTIVITY, not scene start - long continuous
                // scenes were force-re-stamped at minute 5, dropping autonomy-path partners (no explicit
                // AcceptSex -> no carryover) back to tier_prompt mid-act: speak style lost + re-asked.
                $sceneFreshnessTs = max((int)$intimacyStatus["scene_start_time"], (int)($intimacyStatus["last_scene_update_time"] ?? 0));
                $timeSinceSceneStart = time() - $sceneFreshnessTs;
                if ($timeSinceSceneStart > 300) {  // 5 minutes with NO scene activity
                    error_log("[AIAGENTNSFW] Stale scene data for $actor (last activity " . $timeSinceSceneStart . "s ago) - treating as new scene");
                    $isNewScene = true;
                }
            }
            // PHASE-WITHOUT-SCENE (fix 2026-07-01d): a lingering phase (whiskey_dick/accepted/...) with NO
            // scene_start_time and NO scene_actors is leftover state, not a live scene - and the staleness
            // escape above cannot run without scene_start_time. Without this, the actor NEVER re-enters
            // tier_prompt, so the tier/consent addendum stack never fires on the next scene (the multi-
            // partner "they never accepted / no speak style" bug). A live genuine refusal always has
            // scene_actors set, so it cannot take this path.
            if (!$isNewScene && empty($intimacyStatus["scene_start_time"]) && empty($intimacyStatus["scene_actors"])) {
                error_log("[AIAGENTNSFW] Lingering phase '" . ($intimacyStatus["scene_phase"] ?? '') . "' with no scene record for $actor - treating as NEW scene");
                $isNewScene = true;
            }
            if ($isNewScene && $refusalLatched && $sameSceneActors) {
                $lastStopTime = (int)($intimacyStatus["last_scene_stop_time"] ?? 0);
                $canRestartAtIntro = $isTier0IntroScene
                    && $lastStopTime > 0
                    && (time() - $lastStopTime) >= max(10, (int)_getNsfwSetting('SCENE_STOP_RETRY_SECONDS', 3) * 2)
                    && empty($intimacyStatus["request_scene_stop"])
                    && empty($intimacyStatus["stop_command_sent"]);
                if ($canRestartAtIntro) {
                    error_log("[AIAGENTNSFW] Clearing stale refusal latch for $actor: OStim returned to tier-0 intro after prior stop");
                } else {
                    $isNewScene = false;
                    error_log("[AIAGENTNSFW] Refusal latch preserved for $actor across OStim stage/position change; waiting for total scene exit");
                }
            }

	            if ($isNewScene) {
	                $consentCarryoverSeconds = (int)_getNsfwSetting('SCENE_CONSENT_CARRYOVER_SECONDS', 1800);
	                $consentCarryoverSeconds = max(0, $consentCarryoverSeconds);
	                $actorPartnerNames = array_values(array_filter($orderedActorList, function($sceneActor) use ($actor) {
	                    return $sceneActor !== $actor && $sceneActor !== ($GLOBALS["PLAYER_NAME"] ?? "");
	                }));
	                $playerName = $GLOBALS["PLAYER_NAME"] ?? "";
	                $priorPartnerMatches = ($priorAcceptPartner === "" || strcasecmp($priorAcceptPartner, $playerName) === 0 || in_array($priorAcceptPartner, $orderedActorList, true));
	                $recentAcceptedSex = $priorAcceptedSex
	                    && (!function_exists('aiagentNsfwRelTypeSexEligible') || aiagentNsfwRelTypeSexEligible($actor)) // eligibility required to carry acceptance (fix 2026-07-01j)
	                    && $sceneTier >= 3
	                    && $consentCarryoverSeconds > 0
	                    && $priorAcceptTime > 0
	                    && (time() - $priorAcceptTime) <= $consentCarryoverSeconds
	                    && $priorPartnerMatches;

                // NEW SCENE: standing/intro and actual sex use the relationship/model gate.
                // Tier 1/2 OStim affection scenes are not mini sex gates; they are context only.
                // A tier-0 idle beat right after an affection tool call IS an affection gesture
                // (hand-hold/hug blipping through OStim) - classify it tier 1, never the sex ask.
                if (!isset($sceneAffectionBeat)) {
                    $sceneAffectionBeat = false;
                    if ($sceneTier === 0) {
                        try {
                            foreach ($orderedActorList as $affBeatActor) {
                                if ($affBeatActor === ($GLOBALS["PLAYER_NAME"] ?? "")) { continue; }
                                $affBeatRow = $GLOBALS["db"]->fetchOne("SELECT 1 AS x FROM actions_issued WHERE actorname='" . $GLOBALS["db"]->escape($affBeatActor) . "' AND action IN ('GiveHug','Kiss','HoldHands') AND localts > " . (time() - 60) . " LIMIT 1");
                                if ($affBeatRow) { $sceneAffectionBeat = true; break; }
                            }
                        } catch (Exception $e) {
                            // unknown -> keep the stricter sex-gate classification
                        }
                    }
                }
                $effectiveTier = ($sceneAffectionBeat && $sceneTier === 0) ? 1 : $sceneTier;
                $isNonSexAffectionScene = ($effectiveTier > 0 && $effectiveTier <= 2);
                $intimacyStatus["level"] = 0;
                $intimacyStatus["intensity_tier"] = $effectiveTier;
                $intimacyStatus["scene_phase"] = $isNonSexAffectionScene ? "affection" : "tier_prompt";
                $intimacyStatus["scene_is_idle"] = in_array("idle", $sexTags);
                $intimacyStatus["scene_start_time"] = time();
                $intimacyStatus["scene_actors"] = $orderedActorList;
                $intimacyStatus["current_primary_partner"] = $primaryPartner;
                $intimacyStatus["forced_scene"] = false;       // fresh consent each new scene - never inherit a prior refusal
                $intimacyStatus["refusal_expressed"] = false;
                $intimacyStatus["accepted_sex"] = false;
                $intimacyStatus["accepted_affection"] = $isNonSexAffectionScene;
                $intimacyStatus["had_sex_in_scene"] = false;
                $intimacyStatus["tier_prompt_sent"] = $isNonSexAffectionScene ? null : false;
                // The standing/intro guidance is one-shot per scene. The scene thread key is
                // actor-derived and is reused by later scenes with the same participants, so
                // explicitly clear its per-scene latch here instead of relying on a new key.
                $intimacyStatus["standing_intro_shown_thread"] = null;
                $intimacyStatus["request_scene_stop"] = false;
                $intimacyStatus["stop_command_sent"] = false;
                $intimacyStatus["last_scene_stop_time"] = null;
                $intimacyStatus["scene_stop_retry_count"] = 0;
                $intimacyStatus["last_forced_refusal_scene_key"] = null;
                $intimacyStatus["last_refusal_speech_time"] = null;
                $intimacyStatus["last_refusal_speech_key"] = null;
                // FRESH CONSENT EACH NEW SCENE: a prior RefuseSex set refused_until_scene_end=true, which gates
                // consent (functions.php 2073/2112/2228 -> $_consentedFns=false, stripping sex functions AND the
                // speech-style/kink unlock). It was never cleared on scene exit/start, so a scene SHE triggers
                // inherited the stale refusal and kicked the player out instantly with no speech style. Clear it.
                $intimacyStatus["refused_until_scene_end"] = false;
                $intimacyStatus["npc_refusal_dialogue_only"] = false;
                $intimacyStatus["orgasmed"] = false;
	                $intimacyStatus["orgasm_generated"] = false;
	                $intimacyStatus["orgasm_generated_text"] = "";
	                $intimacyStatus["orgasm_generated_text_original"] = "";
	                if ($isNonSexAffectionScene) {
	                    error_log("[AIAGENTNSFW] New non-sex affection scene for $actor - context-only phase (tier {$sceneTier}, actors: " . implode(", ", $orderedActorList) . ", primary: $primaryPartner)");
	                } else {
	                    error_log("[AIAGENTNSFW] New scene for $actor - starting tier_prompt phase (actors: " . implode(", ", $orderedActorList) . ", primary: $primaryPartner)");
	                }
	                if (!$isNonSexAffectionScene && $recentAcceptedSex) {
	                    $intimacyStatus["accepted_sex"] = true;
	                    $intimacyStatus["sex_partner"] = $priorAcceptPartner !== "" ? $priorAcceptPartner : $playerName;
	                    $intimacyStatus["last_accept_sex_time"] = $priorAcceptTime;
	                    $intimacyStatus["last_accept_sex_partner"] = $priorAcceptPartner !== "" ? $priorAcceptPartner : $playerName;
	                    $intimacyStatus["scene_phase"] = "accepted";
	                    $intimacyStatus["tier_prompt_sent"] = true;
	                    error_log("[AIAGENTNSFW] Carried recent AcceptSex into active scene for $actor (partner={$intimacyStatus["sex_partner"]}, age=" . (time() - $priorAcceptTime) . "s)");
	                }

                // ============================================
                // STORE TIER PROMPT INFO FOR ALL ACTORS
                // ============================================
                // We compute and store is_slave/is_prostitute for EVERY actor
                // so prerequest.php can inject the correct tier prompt when
                // each actor speaks (not just the first speaker)
                // ============================================
                require_once __DIR__ . "/nsfw_relationship.php";

                // Skip player - only process NPCs
                if ($actor !== $GLOBALS["PLAYER_NAME"]) {
                    // Determine NPC type for this actor
                    $npcManager = new NpcMaster();
                    $npcData = $npcManager->getByName($actor);
                    // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
                    require_once __DIR__ . "/nsfw_data.php";
                    $extended_data = NsfwNpcData::get($actor);
                    $metadata = $npcManager->getMetadata($npcData);

                    $isSlave = isNpcSlave($actor);
                    $isCourtesan = false;
                    $modsToCheck = ["The Naked DragonSSE.esp", "prostitutes.esp"];
                    if (is_array($metadata["mods"])) {
                        foreach ($modsToCheck as $mod) {
                            $isCourtesan = $isCourtesan || in_array($mod, $metadata["mods"]);
                        }
                    }
                    $isProstitute = (!empty($extended_data['is_prostitute']) ||
                                    !empty($extended_data['profession_prostitute']) ||
                                    $isCourtesan) && empty($extended_data['is_slave']); // slave dominates - mutually exclusive types

                    // Store NPC type info in intimacy status for prerequest.php to use
                    $intimacyStatus["npc_is_slave"] = $isSlave;
                    $intimacyStatus["npc_is_prostitute"] = $isProstitute;

                    // Store affinity for slaves
                    if ($isSlave) {
                        try {
                            $relationship = RelationshipManager::getPlayerRelationship($actor);
                            $intimacyStatus["slave_affinity"] = $relationship['aff'] ?? 0;
                            error_log("[AIAGENTNSFW] Stored slave info for $actor: affinity={$intimacyStatus["slave_affinity"]}");
                        } catch (Exception $e) {
                            $intimacyStatus["slave_affinity"] = 0;
                            error_log("[AIAGENTNSFW] Failed to get slave affinity for $actor: " . $e->getMessage());
                        }
                    }

                    error_log("[AIAGENTNSFW] Stored NPC type for $actor: slave=" . ($isSlave ? "YES" : "no") . ", prostitute=" . ($isProstitute ? "YES" : "no"));

                    // Slaves auto-accept (they can't refuse)
                    // Prostitutes auto-accept but need payment negotiation
                    // Regular NPCs stay at tier_prompt for affinity-based accept/refuse
	                    if ($isSlave) {
	                        $intimacyStatus["scene_phase"] = "accepted";
	                        error_log("[AIAGENTNSFW] Auto-accepting for $actor (slave) on scene start");
	                    } else if ($isProstitute && !$frameworkActorAggressor) {
	                        // Paid status is authoritative from the durable ledger (survives the intimacy
	                        // clobber race; consumed on player orgasm / window expiry). This is what lets a
	                        // client who paid during negotiation start the scene without a bogus refusal.
	                        $ledgerPaid = function_exists('aiagentNsfwPaymentLedgerActive') ? aiagentNsfwPaymentLedgerActive($actor) : !empty($intimacyStatus["payment_confirmed"]);
	                        $alreadyPaid = $ledgerPaid && empty($intimacyStatus["service_completed"]);
	                        $intimacyStatus["scene_phase"] = $alreadyPaid ? "accepted" : "tier_prompt";
	                        $intimacyStatus["level"] = 0;
	                        $intimacyStatus["accepted_sex"] = false;
	                        $intimacyStatus["is_transaction"] = true;
	                        $intimacyStatus["payment_confirmed"] = $alreadyPaid;
	                        $intimacyStatus["negotiation_phase"] = !$alreadyPaid;
	                        $intimacyStatus["ready_for_service"] = $alreadyPaid;
	                        $intimacyStatus["tier_prompt_sent"] = $alreadyPaid ? true : false;
	                        if ($alreadyPaid) {
	                            $intimacyStatus["refusal_expressed"] = false;
	                            $intimacyStatus["request_scene_stop"] = false;
	                            $intimacyStatus["stop_command_sent"] = false;
	                            $intimacyStatus["forced_scene"] = false;
	                            $intimacyStatus["last_refusal_speech_key"] = null;
	                            $intimacyStatus["last_refusal_speech_time"] = null;
	                            $intimacyStatus["last_forced_refusal_scene_key"] = null;
	                            error_log("[AIAGENTNSFW] Paid prostitute transaction resumed for $actor - payment already confirmed, service unlocked");
	                        } else {
	                            error_log("[AIAGENTNSFW] Prostitute transaction started for $actor - negotiation tier prompt, awaiting TakeGold");
	                        }
	                    }
                    // Regular NPCs stay at tier_prompt only for standing/intro and actual sex.
                    // Tier 1/2 affection scenes stay context-only.

                    if ($actor === $GLOBALS["HERIKA_NAME"] && $isProstitute && !$frameworkActorAggressor && empty($intimacyStatus["payment_confirmed"])) {
                        // PROSTITUTES: Inject negotiation context with PRICE LIST.
                        // Regular NPC tier/type gates are owned by prerequest.php so one path decides consent.
                        $clientName = $GLOBALS["PLAYER_NAME"] ?? "client";
                        $affinity = 0;
                        try {
                            $relationship = RelationshipManager::getPlayerRelationship($actor);
                            $affinity = $relationship['aff'] ?? 0;
                        } catch (Exception $e) {
                            // Use default 0
                        }
                        $negotiationContext = buildProstituteNegotiationContext($actor, $clientName, $affinity);
                        if (!empty($negotiationContext)) {
                            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $negotiationContext;
                            error_log("[AIAGENTNSFW] Injected NEGOTIATION context with price list for $actor");
                        }
                    }
                }
            } else {
                // CONTINUING SCENE: Progress through phases
                // Continuing scene for $actor

                // ============================================
                // DETECT TRANSITION: Idle → Actual Sex
                // ============================================
                // When scene was idle but now has actual sex tags, mark sex_started
                // This triggers kink/sex prompt injection in prerequest.php
                // ============================================
                $intimacyStatus["intensity_tier"] = $sceneTier;
                $currentScenePhase = $intimacyStatus["scene_phase"] ?? '';
                $hasAcceptedAffection = !empty($intimacyStatus["accepted_affection"]);
                $hasAcceptedSex = !empty($intimacyStatus["accepted_sex"]);
                $isPrivilegedConsentPath = !empty($intimacyStatus["npc_is_slave"])
                    || (!empty($intimacyStatus["npc_is_prostitute"]) && !empty($intimacyStatus["payment_confirmed"]))
                    || !empty($intimacyStatus["scene_actor_is_aggressor"]);
                $isUnpaidProstituteTransaction = !empty($intimacyStatus["npc_is_prostitute"])
                    && !empty($intimacyStatus["is_transaction"])
                    && !empty($intimacyStatus["negotiation_phase"])
                    && empty($intimacyStatus["payment_confirmed"]);
                // MODEL-DRIVEN CONSENT (AcceptSex removed): a regular non-prostitute NPC who has NOT refused is
                // implicitly consenting - the model itself chose to start/continue the scene. Prostitutes are the
                // ONLY ones still behind a hard gate (payment via $isPrivilegedConsentPath). Without this,
                // accepted_sex is never set anymore so sex_started stayed false and a tier-3 scene SHE starts was
                // dead on arrival ("SEX START ignored - no accepted consent"). This is the missing half of the
                // AcceptSex removal (prerequest already does model-driven consent; this gate was not updated).
                $modelDrivenSexConsent = empty($intimacyStatus["npc_is_prostitute"]);

                if ($currentScenePhase === "affection" && $sceneTier == 3) {
                    if ($hasAcceptedSex || $isPrivilegedConsentPath || $modelDrivenSexConsent) {
                        $intimacyStatus["scene_phase"] = "accepted";
                        $intimacyStatus["tier_prompt_sent"] = true;
                        error_log("[AIAGENTNSFW] Affection escalated back to sex for $actor with existing consent - accepted");
                    } else {
                        $intimacyStatus["scene_phase"] = "tier_prompt";  // affection escalated to actual sex
                        unset($intimacyStatus["tier_prompt_sent"]);
                        error_log("[AIAGENTNSFW] Affection escalated to sex for $actor - starting relationship gate");
                    }
                } else if ($sceneTier > 0 && $sceneTier <= 2 && $currentScenePhase !== "rejected") {
                    // Tier 1/2 OStim affection beats are not sex acceptance/refusal gates.
                    $intimacyStatus["scene_phase"] = "affection";
                    $intimacyStatus["accepted_affection"] = true;
                    $intimacyStatus["sex_started"] = false;  // non-sex affection beat: clear stale sex_started so sex prompts don't fire over affection
                    unset($intimacyStatus["tier_prompt_sent"]);
                    error_log("[AIAGENTNSFW] Tier {$sceneTier} non-sex affection beat -> context-only affection for $actor");
	                } else if ($sceneTier === 0 && $isUnpaidProstituteTransaction && in_array($currentScenePhase, ["accepted","engaged","affection"], true)) {
	                    // Prostitute standing/intro is the negotiation phase, not ordinary affection.
	                    // Keep the transaction prompt alive until payment is confirmed or the scene exits.
	                    $intimacyStatus["scene_phase"] = "tier_prompt";
	                    $intimacyStatus["level"] = 0;
	                    $intimacyStatus["accepted_sex"] = false;
	                    $intimacyStatus["accepted_affection"] = false;
	                    unset($intimacyStatus["tier_prompt_sent"]);
	                    error_log("[AIAGENTNSFW] Prostitute tier {$sceneTier} standing/intro beat clamped to transaction negotiation for $actor");
                } else if ($sceneTier === 0 && (in_array($currentScenePhase, ["accepted","engaged"], true) || $hasAcceptedAffection || $hasAcceptedSex || $isPrivilegedConsentPath)) {
                    // DE-ESCALATION: scene dropped to a NON-sexual beat (idle/intro/standing-apart) while a sexual
                    // phase was still active - usually a fresh OStim scene START inheriting the PRIOR scene's
                    // engaged/accepted_sex. Revert to affection so the sexual scene desc stops being injected over a
                    // standing/idle moment. On a scene start (intro tag) also CLEAR consent so sex must be re-earned
                    // through the rel/tier gate if it escalates again (a standing intro is never consent); mid-scene
                    // idle beats keep consent so active sex doesn't demand a re-accept every beat.
                    $intimacyStatus["scene_phase"] = "affection";
                    $intimacyStatus["sex_started"] = false;  // tier-0 standing beat: sex is NOT happening now. Clear the
                                                             // stale flag so she stops getting sex prompts over a standing
                                                             // scene (was "assumes we're fucking" + sex/greeting oscillation).
                    unset($intimacyStatus["tier_prompt_sent"]);
                    $_isIntro = in_array("intro", $sexTags, true);
                    if ($_isIntro) { $intimacyStatus["accepted_sex"] = false; }
                    error_log("[AIAGENTNSFW] DE-ESCALATION: tier {$sceneTier} standing/intro beat -> affection (sex_started cleared)" . ($_isIntro ? " + cleared consent (intro/new scene)" : "") . " for $actor");
                }
                $wasIdle = !empty($intimacyStatus["scene_is_idle"]);
                $isNowIdle = in_array("idle", $sexTags);

	                if ($sceneTier == 3 && $wasIdle && !$isNowIdle && empty($intimacyStatus["sex_started"])) {
	                    $npcSceneGateDisabled = !empty($intimacyStatus["is_npc_scene"]) && !empty($intimacyStatus["npc_affinity_gate_disabled"]);
	                    $refusalLatchedNow = (($intimacyStatus["scene_phase"] ?? null) === "rejected")
	                        || !empty($intimacyStatus["refusal_expressed"])
	                        || !empty($intimacyStatus["refused_until_scene_end"])  // sticky refusal fortifies until scene exit - sex can't re-start
	                        || !empty($intimacyStatus["request_scene_stop"]);
	                    $mayMarkSexStarted = !$refusalLatchedNow && ($hasAcceptedSex || $isPrivilegedConsentPath || $npcSceneGateDisabled || $modelDrivenSexConsent);
	                    if (!$mayMarkSexStarted) {
	                        $intimacyStatus["sex_started"] = false;
	                        error_log("[AIAGENTNSFW] SEX START ignored for $actor - no accepted consent or refusal is latched");
	                    } else {
	                    // TRANSITION: Idle → Actual Sex!
	                    $intimacyStatus["scene_is_idle"] = false;
	                    $intimacyStatus["sex_started"] = true;
	                    error_log("[AIAGENTNSFW] SEX STARTED for $actor - transitioning from idle to actual sex");
	                    }
	                } else if ($sceneTier == 3 && !$wasIdle && !$isNowIdle && empty($intimacyStatus["sex_started"])) {
	                    $npcSceneGateDisabled = !empty($intimacyStatus["is_npc_scene"]) && !empty($intimacyStatus["npc_affinity_gate_disabled"]);
	                    $refusalLatchedNow = (($intimacyStatus["scene_phase"] ?? null) === "rejected")
	                        || !empty($intimacyStatus["refusal_expressed"])
	                        || !empty($intimacyStatus["refused_until_scene_end"])  // sticky refusal fortifies until scene exit - sex can't re-start
	                        || !empty($intimacyStatus["request_scene_stop"]);
	                    $mayMarkSexStarted = !$refusalLatchedNow && ($hasAcceptedSex || $isPrivilegedConsentPath || $npcSceneGateDisabled || $modelDrivenSexConsent);
	                    if (!$mayMarkSexStarted) {
	                        $intimacyStatus["sex_started"] = false;
	                        error_log("[AIAGENTNSFW] SEX START ignored for $actor - no accepted consent or refusal is latched");
	                    } else {
	                    // Scene started directly with sex (no idle phase)
	                    $intimacyStatus["sex_started"] = true;
	                    error_log("[AIAGENTNSFW] SEX STARTED for $actor - scene started with sex (no idle)");
	                    }
	                }

	                $historyRefusalLatched = (($intimacyStatus["scene_phase"] ?? null) === "rejected")
	                    || !empty($intimacyStatus["refusal_expressed"])
	                    || !empty($intimacyStatus["refused_until_scene_end"])
	                    || !empty($intimacyStatus["request_scene_stop"]);
	                $npcSceneGateDisabledForHistory = !empty($intimacyStatus["is_npc_scene"])
	                    && !empty($intimacyStatus["npc_affinity_gate_disabled"]);
	                if (aiagentNsfwShouldLatchPlayerSceneSexHistory(
	                    $sceneTier,
	                    !empty($intimacyStatus["sex_started"]),
	                    $historyRefusalLatched,
	                    !empty($intimacyStatus["accepted_sex"]),
	                    $isPrivilegedConsentPath,
	                    $npcSceneGateDisabledForHistory,
	                    $modelDrivenSexConsent
	                )) {
	                    $intimacyStatus["had_sex_in_scene"] = true;
	                }

                // Update idle status for current stage
                $intimacyStatus["scene_is_idle"] = $isNowIdle;
            }

            // A framework-declared aggressor initiated this encounter. This is not the ordinary
            // player-request relationship gate: the NPC's own framework role is authoritative.
            if ($frameworkActorAggressor) {
                $intimacyStatus["scene_phase"] = "engaged";
                $intimacyStatus["level"] = 2;
                $intimacyStatus["accepted_sex"] = true;
                $intimacyStatus["tier_prompt_sent"] = true;
                $intimacyStatus["refusal_expressed"] = false;
                $intimacyStatus["refused_until_scene_end"] = false;
                $intimacyStatus["request_scene_stop"] = false;
                $intimacyStatus["stop_command_sent"] = false;
                $intimacyStatus["is_transaction"] = false;
                $intimacyStatus["negotiation_phase"] = false;
                if ($sceneTier >= 3) {
                    $intimacyStatus["sex_started"] = true;
                    $intimacyStatus["had_sex_in_scene"] = true;
                }
                error_log("[AIAGENTNSFW] Framework forced scene: {$actor} is aggressor, player is victim; ordinary relationship gate bypassed");
            }

            // Per-tick heartbeat for the PLAYER route. Player scenes tick (~every 2s) while live; if OStim's
            // end event is lost the scene never tears down. This stamp lets the stale-player-scene safety net
            // (endStalePlayerSceneIfNeeded) tell a live scene from one that silently stopped ticking.
            $intimacyStatus["last_scene_update_time"] = time();
            updateIntimacyForActor($actor, $intimacyStatus);
        }

        // ============================================
        // PROPAGATE scene_actors TO ALL PARTICIPANTS
        // ============================================
        // Every NPC in the scene needs scene_actors set so they can be identified
        // as scene participants. This is purely informational - prerequest.php
        // handles the phase/level logic based on arousal gating setting.
        // ============================================
        foreach ($orderedActorList as $otherActor) {
            if ($otherActor === $actor || $otherActor === $GLOBALS["PLAYER_NAME"]) continue;

            $otherIntimacy = getIntimacyForActor($otherActor);

            // If this actor doesn't have scene_actors set, propagate it
            if (empty($otherIntimacy["scene_actors"]) || $otherIntimacy["scene_actors"] !== $orderedActorList) {
                $otherIntimacy["scene_actors"] = $orderedActorList;
                updateIntimacyForActor($otherActor, $otherIntimacy);
                // Propagated scene_actors to $otherActor
            }
        }

        // ============================================
        // CLEAR STALE EVENTS FOR SCENE PARTICIPANTS
        // ============================================
        // On scene position changes, remove pending stale speak/backlog events for
        // participants. ext_nsfw_sexcene is the authoritative current-state prompt;
        // queued chatnf_sl can arrive out of order and must not inject old sex cues.
        // ============================================
        if (isset($GLOBALS["db"])) {
            foreach ($orderedActorList as $clearActor) {
                if ($clearActor === $GLOBALS["PLAYER_NAME"]) continue;
                $escapedActor = $GLOBALS["db"]->escape($clearActor);
                $GLOBALS["db"]->delete("eventlog",
                    "type in ('prechat','rechat','chatnf_sl','chatnf_sl_nr') and people like '%|$escapedActor|%' and localts>" . (time() - 300)
                );
            }
            // Cleared stale events for scene participants
        }

        // Look up scene description

        // Fill descriptions - DUAL LOOKUP: SQL first (user custom), then OStim JSON (automatic)
        $sceneActorSlots = !empty($rawActorList) ? $rawActorList : $orderedActorList;
        $cleanedSceneDesc = getSceneDescription($sexStageName, $sceneActorSlots, $sexTags);   // tags: a scene the import lacks is described from them (LOCAL, branch reldyn-scenes)

        // Legacy fallback if scene_lookup.php fails
        if (empty($cleanedSceneDesc)) {
            $sceneDescription = findRowByFirstColumn(__DIR__ . "/scene_descriptions.csv", $sexStageName);
            if (!$sceneDescription) {
                $sceneDescription = "{actor0},{actor1},{actor2},{actor3},{actor4} are having an intimate moment";
            }
            $sceneDescriptionParsed = preg_replace_callback('/\{actor(\d+)\}/', function ($matches) use ($sceneActorSlots) {
                $index = (int) $matches[1];
                return $sceneActorSlots[$index] ?? $matches[0];
            }, $sceneDescription);
            $cleanedSceneDesc = preg_replace('/\{actor\d+\}/', '', $sceneDescriptionParsed);
        }

        $storedSceneDesc = $cleanedSceneDesc;
	        $isIdleIntroBeat = ($sceneTier === 0 && (in_array("idle", $sexTags, true) || in_array("intro", $sexTags, true)))
	            || stripos((string)$sexStageName, 'standingapart') !== false;
	        $standingIntroAlreadyShown = true;
	        $hasStandingNpc = false;
	        foreach ($orderedActorList as $standingStateActor) {
	            if ($standingStateActor === ($GLOBALS["PLAYER_NAME"] ?? "")) { continue; }
	            $hasStandingNpc = true;
	            $standingState = getIntimacyForActor($standingStateActor);
	            if ((string)($standingState["standing_intro_shown_thread"] ?? "") !== (string)$sceneThreadKey) {
	                $standingIntroAlreadyShown = false;
	                break;
	            }
	        }
	        if (!$hasStandingNpc) { $standingIntroAlreadyShown = false; }
	        $sexAlreadyUnderwayForStanding = self::sceneSexAlreadyUnderway($orderedActorList);
	        // prerequest treats every tier-0 report as a standing/intro beat, even when a
	        // particular animation pack omits the literal idle/intro tags. Keep this mode
	        // decision equally broad so those packs cannot replay the entry prompt forever.
	        $isStandingPromptBeat = ($sceneTier === 0)
	            || stripos((string)$sexStageName, 'standingapart') !== false;
	        $standingPromptMode = aiagentNsfwPlayerStandingPromptMode(
	            $isStandingPromptBeat,
	            $standingIntroAlreadyShown,
	            $sexAlreadyUnderwayForStanding,
	            false
	        );
	        if ($isIdleIntroBeat) {
	            // A recent affection tool call means this idle "scene" is a hand-hold/hug gesture
	            // blipping through OStim, not an advance - the DB flavor text for the idle
	            // ("wants more from you") framed hand-holding as a proposition and NPCs reacted
	            // to it as one. Override BOTH the prompt copy and the stored copy.
	            $affectionRecent = false;
	            try {
	                foreach ($orderedActorList as $affA) {
	                    if ($affA === $GLOBALS["PLAYER_NAME"]) { continue; }
	                    $affRow = $GLOBALS["db"]->fetchOne("SELECT 1 AS x FROM actions_issued WHERE actorname='" . $GLOBALS["db"]->escape($affA) . "' AND action IN ('GiveHug','Kiss','HoldHands') AND localts > " . (time() - 60) . " LIMIT 1");
	                    if ($affRow) { $affectionRecent = true; break; }
	                }
	            } catch (Exception $e) {
	                // fall through to the neutral decision framing
	            }
	            $standingPromptMode = aiagentNsfwPlayerStandingPromptMode(
	                true,
	                $standingIntroAlreadyShown,
	                $sexAlreadyUnderwayForStanding,
	                $affectionRecent
	            );
	            if ($standingPromptMode === 'affection') {
	                $cleanedSceneDesc = implode(" and ", $orderedActorList) . " share a tender, non-sexual moment of affection (a held hand, an embrace). Nothing sexual is happening or being asked for; respond with simple warmth in line with your feelings.";
	            } else if ($standingPromptMode === 'entry') {
	                $cleanedSceneDesc = implode(" and ", $orderedActorList) . " are in an intimate starting stance before anything has been accepted. Nothing sexual has happened yet; the NPC must decide whether to accept or refuse.";
	            } else if ($standingPromptMode === 'breather') {
	                $cleanedSceneDesc = implode(" and ", $orderedActorList) . " remain together in the same ongoing intimate encounter during a standing or idle pause. Sexual activity already happened in this scene; this is a breather between acts, not a new scene. Preserve the established consent and relationship state.";
	            } else {
	                $cleanedSceneDesc = implode(" and ", $orderedActorList) . " remain together in the same active scene during a standing or idle beat. This is a continuation, not a new scene entry. Preserve the current acceptance or refusal state and do not restart the opening decision.";
	            }
	            $storedSceneDesc = $cleanedSceneDesc;
	        }
	        aiagentNsfwSceneThreadUpsert($sceneThreadKey, 'player_scene', $orderedActorList, $sexStageName, $storedSceneDesc, 'ext_nsfw_sexcene');

        // ============================================
        // STORE SCENE DESCRIPTION IN INTIMACY DATA
        // ============================================
        // So NPC events (chatnf_sl) know the current scene when they speak.
        // Without this, the NPC has no idea what position/animation is happening.
        // ============================================
        foreach ($orderedActorList as $storeActor) {
            if ($storeActor === $GLOBALS["PLAYER_NAME"]) continue;
            $storeIntimacy = getIntimacyForActor($storeActor);
            $storeIntimacy["current_scene_desc"] = $storedSceneDesc;
            $storeIntimacy["current_scene_tags"] = $sexTags;
	            $storeIntimacy["current_scene_name"] = $sexSceneName;
	            $storeIntimacy["current_scene_thread_key"] = $sceneThreadKey;
	            $storeIntimacy["current_primary_partner"] = $primaryPartner;
            $storeIntimacy["scene_actors"] = $orderedActorList;
            $storeIntimacy["raw_scene_actor_slots"] = $sceneActorSlots;
            $storeIntimacy["actor_roles"] = $actorRoles;
            // Track this NPC's specific role in the scene
            $storeIntimacy["my_role_tags"] = $actorRoles[$storeActor] ?? [];
            $storeIntimacy["is_active_participant"] = ($storeActor === $primaryPartner);
            // If a scene begins directly in affection or sex, the opening standing
            // decision was already bypassed by a real scene stage. Consume the entry
            // opportunity so a later return to tier 0 cannot claim this is the start.
            if (!$isStandingPromptBeat) {
                $storeIntimacy["standing_intro_shown_thread"] = $sceneThreadKey;
            }
            updateIntimacyForActor($storeActor, $storeIntimacy);
        }

        // Check whether any actor needs the first tier prompt. Once tier_prompt_sent is true,
        // standing/intro repeats must stay silent while waiting on the model/tool response.
        // Actual sex escalation still passes through so the refusal/stop logic can fire.
        $anyActorNeedsTierPrompt = false;
        $anyActorHoldingTierPrompt = false;
        if ($sceneTier === 0 || $sceneTier >= 3) {
            foreach ($orderedActorList as $checkActor) {
                if ($checkActor === $GLOBALS["PLAYER_NAME"]) continue;
                $checkIntimacy = getIntimacyForActor($checkActor);
                if (($checkIntimacy["scene_phase"] ?? null) === "tier_prompt") {
                    if (empty($checkIntimacy["tier_prompt_sent"])) {
                        $anyActorNeedsTierPrompt = true;
                        break;
                    }
                    $anyActorHoldingTierPrompt = true;
                }
            }
        }
        $suppressStandingHeldTierPrompt = ($sceneTier === 0 && !$anyActorNeedsTierPrompt && $anyActorHoldingTierPrompt);

        // ============================================
        // CONTEXT INJECTION: tier_prompt vs engaged
        // ============================================
        // tier_prompt = Inject tier prompt from database - NPC decides accept/refuse
        // accepted/engaged = Full intimate scene context
        // ============================================
        if ($anyActorNeedsTierPrompt) {
            // TIER_PROMPT PHASE: Inject the relationship/affair gate before any scene context.
            // This must beat generic affection prompts; otherwise OStim can advance without consent
            // and the model receives "respond warmly" instead of the correct accept/refuse gate.
            require_once __DIR__ . "/nsfw_relationship.php";
            if ($sceneTier === 0) {
                self::suppressHistoricContextForCurrentRequest("tier0 relationship gate");
            }

            $tierPromptNpc = null;
            foreach ($orderedActorList as $checkActor) {
                if ($checkActor === $GLOBALS["PLAYER_NAME"]) continue;
                $checkIntimacy = getIntimacyForActor($checkActor);
                if (($checkIntimacy["scene_phase"] ?? null) === "tier_prompt"
                    && empty($checkIntimacy["tier_prompt_sent"])) {
                    $tierPromptNpc = $checkActor;
                    break;
                }
            }

            if ($tierPromptNpc) {
                $tierPromptIsProstitute = function_exists('isProstitute') && isProstitute($tierPromptNpc);
                if ($sceneTier >= 3 && !empty($cleanedSceneDesc) && !$tierPromptIsProstitute) {
                    $tierPromptContext = NsfwRelationship::buildSceneContext($tierPromptNpc, $orderedActorList, false);
                    $tierPromptContext .= "\n\n<active_scene_context>\n";
                    $tierPromptContext .= "The active OStim scene has already started: {$cleanedSceneDesc}\n";
                    // Consent is model-driven: no hardcoded accept/refuse cue here. Tier prompt + scene desc + RefuseSex suffice.
                    $tierPromptContext .= "</active_scene_context>";
                } else {
                    $tierPromptContext = NsfwRelationship::buildSceneContext($tierPromptNpc, $orderedActorList, $tierPromptIsProstitute);
                    if ($sceneTier >= 3 && $tierPromptIsProstitute) {
                        $tierPromptContext .= "\n\n<payment_gate_context>\nThe OStim scene has escalated into active sex, but your prostitute transaction is not confirmed paid by the TakeGold tool. This is a nonpayment boundary problem. Refuse because payment was not confirmed and choose the RefuseSex action/tool so the scene starts exiting. Do not ask for payment as if nothing happened. Do not act as if paid sex is underway.\n</payment_gate_context>";
                    }
                }

                if (!empty($tierPromptContext)) {
                    $GLOBALS["gameRequest"][3] = $tierPromptContext;
                    unset($GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"]);
                    unset($GLOBALS["AIAGENTNSFW_SCENE_PLAYER_REQUEST_OVERRIDE"]);
                    error_log("[AIAGENTNSFW] tier_prompt phase - injected tier prompt for $tierPromptNpc");
                } else {
                    $GLOBALS["gameRequest"][3] = "";
                    error_log("[AIAGENTNSFW] tier_prompt phase - no tier prompt found, cleared scene context");
                }
            } else {
                $GLOBALS["gameRequest"][3] = "";
                error_log("[AIAGENTNSFW] tier_prompt phase - no NPC in tier_prompt found");
            }
        } else if ($suppressStandingHeldTierPrompt) {
            $GLOBALS["gameRequest"][3] = "";
            unset($GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"]);
            unset($GLOBALS["AIAGENTNSFW_SCENE_PLAYER_REQUEST_OVERRIDE"]);
            error_log("[AIAGENTNSFW] tier-0 standing/intro already prompted - suppressing repeat model turn while awaiting AcceptSex/RefuseSex");
        } else if ($sceneTier >= 3 && ($unpaidProstituteActor = self::sceneUnpaidOrRefusedProstituteName($orderedActorList)) !== "" && !aiagentNsfwProstitutePaymentWaived($unpaidProstituteActor)) {
            $nonpaymentIntimacy = getIntimacyForActor($unpaidProstituteActor);
            $nonpaymentIntimacy["scene_phase"] = "rejected";
            $nonpaymentIntimacy["accepted_sex"] = false;
            $nonpaymentIntimacy["refusal_expressed"] = true;
            $nonpaymentIntimacy["request_scene_stop"] = true;
            updateIntimacyForActor($unpaidProstituteActor, $nonpaymentIntimacy);
            if (function_exists('aiagentNsfwSceneStopRetryDue') && aiagentNsfwSceneStopRetryDue($nonpaymentIntimacy)
                && function_exists('aiagentNsfwQueuePlayerSceneStop')
                && aiagentNsfwQueuePlayerSceneStop($unpaidProstituteActor, aiagentNsfwSceneExitCommand($nonpaymentIntimacy))) {
                $nonpaymentIntimacy = getIntimacyForActor($unpaidProstituteActor);
                if (function_exists('aiagentNsfwMarkSceneStopQueued')) {
                    aiagentNsfwMarkSceneStopQueued($nonpaymentIntimacy);
                } else {
                    $nonpaymentIntimacy["stop_command_sent"] = true;
                    $nonpaymentIntimacy["last_scene_stop_time"] = time();
                }
                updateIntimacyForActor($unpaidProstituteActor, $nonpaymentIntimacy);
            }

            $partnerNames = array_filter($orderedActorList, function($a) { return $a !== $GLOBALS["PLAYER_NAME"]; });
            $partnerStr = implode(" and ", $partnerNames);
            $npRefusalTmpl = trim((string)getGlobalPrompt('prostitute_nonpayment_refusal'));
            if ($npRefusalTmpl === '') {
                $npRefusalTmpl = "#NPC_NAME# is marked as a prostitute, but payment has not been confirmed by the TakeGold tool. The OStim scene has escalated into active sex without confirmed payment: #SCENE_DESC#. #NPC_NAME# must understand this as a nonpayment boundary problem, not ordinary relationship rejection. Respond in character by refusing because payment was not confirmed, and choose the RefuseSex action/tool so the scene starts exiting. Do not ask for payment as if nothing happened. Do not act as if paid service is underway. Do not moan or express pleasure. Scene actors: #PRIMARY_PARTNER#.";
            }
            $npRefusalTmpl = str_replace(['#NPC_NAME#', '#SCENE_DESC#', '#PRIMARY_PARTNER#'], [$unpaidProstituteActor, $cleanedSceneDesc, $partnerStr], $npRefusalTmpl);
            $GLOBALS["gameRequest"][3] = "<prostitute_nonpayment_refusal>\n"
                . $npRefusalTmpl . "\n"
                . "Scene tags: " . implode(",", $sexTags) . "\n"
                . "</prostitute_nonpayment_refusal>";
            unset($GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"]);
            unset($GLOBALS["AIAGENTNSFW_SCENE_PLAYER_REQUEST_OVERRIDE"]);
            error_log("[AIAGENTNSFW] Injected nonpayment refusal prompt for unpaid prostitute scene escalation: {$unpaidProstituteActor}");
        } else if ($sceneTier <= 2 && self::sceneSexAlreadyUnderway($orderedActorList)) {
            // MID-SCENE BREATHER (tester report 2026-07-04): packs use idle/holding stages BETWEEN acts.
            // Sex already happened in THIS thread - re-injecting the standing "nothing has happened yet,
            // decide whether to accept" framing made partners restart the negotiation mid-encounter.
            // Keep the encounter alive: quiet pause, consent stands, no re-introductions.
            $brPartners = array_filter($orderedActorList, function($a) { return $a !== $GLOBALS["PLAYER_NAME"]; });
            $brPartnerStr = implode(" and ", $brPartners);
            $brText = trim((string)getGlobalPrompt('scene_breather'));
            if ($brText === '') {
                $brText = "A quiet pause in your encounter with #PRIMARY_PARTNER# - a breather between acts, still close, still undressed, still in the moment. The encounter is STILL UNDERWAY and consent was already given. Do NOT restart introductions, do NOT ask whether to begin, do NOT treat this as a new scene. React with afterglow, closeness, teasing, or anticipation of what comes next.";
            }
            $brText = str_replace(['#PRIMARY_PARTNER#', '#NPC_NAME#', '#PLAYER_NAME#'], [$brPartnerStr, $brPartnerStr, $GLOBALS["PLAYER_NAME"] ?? "the player"], $brText);
            $brPrompt = "<scene_breather_prompt>\n"
                . "<scene_behavior>\n{$brText}\n</scene_behavior>\n"
                . "<current_scene_description>\n{$cleanedSceneDesc}\n</current_scene_description>\n"
                . "</scene_breather_prompt>";
            $GLOBALS["gameRequest"][3] = $brPrompt;
            $GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"] = $brPrompt . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
            $GLOBALS["AIAGENTNSFW_SCENE_PLAYER_REQUEST_OVERRIDE"] = "";
            error_log("[AIAGENTNSFW] MID-SCENE BREATHER (tier {$sceneTier}, sex already underway) - injected pause context for $brPartnerStr");
        } else if ($sceneTier === 0) {
            // TIER 0 idle/standing is a presence beat only. Do not let the generic sex-scene cue
            // or the OStim animation text imply touch before the scene actually escalates.
            self::suppressHistoricContextForCurrentRequest("tier0 standing scene");
            $standPartners = array_filter($orderedActorList, function($a) { return $a !== $GLOBALS["PLAYER_NAME"]; });
            $standPartnerStr = implode(" and ", $standPartners);
            if ($standingPromptMode === 'ongoing') {
                $standingCueText = "This is a later standing/idle beat in the SAME active scene with #PRIMARY_PARTNER#, not a new scene entry. Preserve the NPC's existing acceptance or refusal state. Do not repeat the opening decision and do not claim a new physical act unless the current scene description or player dialogue reports one.";
            } else {
                $standingCueText = trim((string)getGlobalPrompt('standing_scene'));
                if ($standingCueText === '') {
                    $standingCueText = "This is a standing/intro scene with #PRIMARY_PARTNER#. Nothing physical has happened yet: no touching, kissing, hugging, undressing, sex, pleasure, friction, penetration, or moaning. React only to presence, eye contact, anticipation, refusal, or conversation. Do not claim contact unless the current scene description or player dialogue explicitly says it happened.";
                }
            }
            $standingCueText = str_replace(['#PRIMARY_PARTNER#', '#NPC_NAME#', '#PLAYER_NAME#'], [$standPartnerStr, $standPartnerStr, $GLOBALS["PLAYER_NAME"] ?? "the player"], $standingCueText);
            $standingPrompt = "<standing_scene_prompt>\n"
                . "<scene_behavior>\n{$standingCueText}\n</scene_behavior>\n"
                . "<current_scene_description>\n{$storedSceneDesc}\n</current_scene_description>\n"
                . "</standing_scene_prompt>";
            $GLOBALS["gameRequest"][3] = $standingPrompt;
            $GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"] = $standingPrompt . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
            $GLOBALS["AIAGENTNSFW_SCENE_PLAYER_REQUEST_OVERRIDE"] = "";
            $GLOBALS["AIAGENTNSFW_AFFECTION_TIER"] = 0;
            error_log("[AIAGENTNSFW] tier-0 idle/standing scene - injected non-contact standing context for $standPartnerStr");
        } else if ($sceneTier > 0 && $sceneTier <= 2) {
            // AFFECTION/ROMANTIC: pass context, NOT a sexual proposition or scene
            $affPartners = array_filter($orderedActorList, function($a) { return $a !== $GLOBALS["PLAYER_NAME"]; });
            $affPartnerStr = implode(" and ", $affPartners);
            $affLabel = ($sceneTier == 1) ? "AFFECTIONATE MOMENT" : "ROMANTIC MOMENT";
            $affPromptKey = ($sceneTier == 1) ? 'affection_scene' : 'romantic_scene';
            $affBehavior = trim((string)getGlobalPrompt($affPromptKey));
            if ($affBehavior === '') {
                $affBehavior = ($sceneTier == 1)
                    ? "Respond warmly and tenderly, as friends or loved ones. This is affectionate and non-sexual. Do not treat this as active sex unless the scene escalates."
                    : "Respond romantically and intimately, with emotional tension, but keep it non-explicit. Do not treat this as active sex unless the scene escalates.";
            }
            $affBehavior = str_replace(['#PRIMARY_PARTNER#', '#NPC_NAME#', '#PLAYER_NAME#'], [$affPartnerStr, $affPartnerStr, $GLOBALS["PLAYER_NAME"] ?? "the player"], $affBehavior);
            $affPrompt = "<affection_scene_prompt>\n"
                . "<scene_type>{$affLabel}</scene_type>\n"
                . "<scene_behavior>\n{$affBehavior}\n</scene_behavior>\n"
                . "<current_scene_description>\n{$cleanedSceneDesc}\n</current_scene_description>\n"
                . "</affection_scene_prompt>";
            $GLOBALS["gameRequest"][3] = $affPrompt;
            $GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"] = $affPrompt . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "");
            $GLOBALS["AIAGENTNSFW_SCENE_PLAYER_REQUEST_OVERRIDE"] = "";
            $GLOBALS["AIAGENTNSFW_AFFECTION_TIER"] = $sceneTier;
            error_log("[AIAGENTNSFW] affection scene (tier $sceneTier) - injected non-sexual context for $affPartnerStr");
	        } else {
	            // ACCEPTED/ENGAGED: Full intimate scene context
	            // Explicitly name the primary partner so the model knows who the player is focused on
	            $partnerNames = array_filter($orderedActorList, function($a) { return $a !== $GLOBALS["PLAYER_NAME"]; });
	            $partnerStr = implode(" and ", $partnerNames);
	            $primaryNote = ($primaryPartner && count($partnerNames) > 1) ? " (currently focused on $primaryPartner)" : "";
	            $GLOBALS["gameRequest"][3] = "#INTIMATE SCENE with $partnerStr$primaryNote: $cleanedSceneDesc. Scene tags:" . implode(",", $sexTags);
	        }
	        $sceneThreadTaggedLogText = !empty($GLOBALS["gameRequest"][3])
	            ? aiagentNsfwSceneThreadTag($GLOBALS["gameRequest"][3], $sceneThreadKey)
	            : "";
	
	        // ============================================
        // CRITICAL: Update PROMPTS array with new gameRequest[3]
        // ============================================
        // prompts.php was loaded BEFORE this runs, so it has stale gameRequest[3].
        // We MUST update the PROMPTS["ext_nsfw_sexcene"]["player_request"] now
        // so that request.php picks up the correct tier prompt (not #INTIMATE SCENE)
        // ============================================
        if (isset($GLOBALS["PROMPTS"]["ext_nsfw_sexcene"])) {
            $GLOBALS["PROMPTS"]["ext_nsfw_sexcene"]["player_request"] = [
                array_key_exists("AIAGENTNSFW_SCENE_PLAYER_REQUEST_OVERRIDE", $GLOBALS)
                    ? (string)$GLOBALS["AIAGENTNSFW_SCENE_PLAYER_REQUEST_OVERRIDE"]
                    : $GLOBALS["gameRequest"][3]
            ];
            if (!empty($GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"])) {
                $GLOBALS["PROMPTS"]["ext_nsfw_sexcene"]["cue"] = [$GLOBALS["AIAGENTNSFW_SCENE_CUE_OVERRIDE"]];
            }
        }

        // FORCE_STOP controls whether LLM runs for this event.
        // With hash-based dedup, ONLY events with NEW scene data reach here.
        // So every event here is either a NEW scene or a SCENE CHANGE — never a repeat.
        //
        // Narrator + new scene (tier_prompt): FORCE_STOP=true — NPC handles via chatnf_sl
        // Narrator + scene CHANGE: FORCE_STOP=false — Narrator narrates the position change
        //   (OStim doesn't fire chatnf_sl on position changes, so nobody else will respond)
        // NPC + tier_prompt: FORCE_STOP=false — NPC responds to accept/refuse
        // NPC + continuing: FORCE_STOP=false — NPC responds to scene change
        if ($suppressStandingHeldTierPrompt) {
            $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
        } else if (!empty($GLOBALS["AIAGENTNSFW_IS_NARRATOR_PROFILE"])) {
            if ($anyActorNeedsTierPrompt) {
                // New scene — NPC will handle via separate chatnf_sl event
                $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
            } else {
                // Scene CHANGE — let Narrator narrate it (only way it shows in log)
                $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = false;
            }
        } else {
            // NPC profile — always let them respond (dedup prevents repeats)
            $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = false;
        }

        if ($isAffectionTransitionBeat) {
            $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
            error_log("[AIAGENTNSFW] Suppressed affection transition speech for {$sexStageName} (tier {$sceneTier}); state updated, final affection beat may speak");
        }

        // IN-SCENE TALKATIVENESS (feature 2026-07-12): "Respond to Scene/Position Changes" unchecked
        // silences the beat-driven line on scene CHANGES only. All scene state above is already
        // processed; the new-scene/consent turn ($anyActorNeedsTierPrompt) ALWAYS speaks - it is the
        // RefuseSex window for scenes started outside SHARMAT tools. Group-scene tick below stays independent.
        if (empty($GLOBALS["AIAGENTNSFW_FORCE_STOP"]) && !$anyActorNeedsTierPrompt
            && !_getNsfwSetting('NSFW_SCENE_SPEAK_ON_SCENE_CHANGE', true)) {
            $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
            error_log("[AIAGENTNSFW] Scene-change response suppressed (Respond to Scene/Position Changes off); state processed silently");
        }

        // RAPID POSITION-CHANGE PACING: every distinct OStim animation ID must update the
        // authoritative scene state above, but it must not also enqueue another model/TTS
        // line immediately. COOLDOWN_SEX_SCENE is the single player-scene speech cadence.
        // Opening consent/decision turns always speak; orgasm/end turns use separate routes.
        if (empty($GLOBALS["AIAGENTNSFW_FORCE_STOP"])) {
            $sceneSpeechCooldown = max(0, (int)_getNsfwSetting('COOLDOWN_SEX_SCENE', 15));
            $sceneSpeechClock = sys_get_temp_dir() . "/nsfw_scene_speech_" . md5($sceneThreadKey) . ".txt";
            $lastSceneSpeech = (int)(@file_get_contents($sceneSpeechClock) ?: 0);
            if (!aiagentNsfwSceneSpeechCadenceAllows(
                $lastSceneSpeech,
                time(),
                $sceneSpeechCooldown,
                $anyActorNeedsTierPrompt
            )) {
                $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
                error_log("[AIAGENTNSFW] Rapid scene-change speech suppressed by {$sceneSpeechCooldown}s cadence; state processed silently");
            } else {
                @file_put_contents($sceneSpeechClock, time(), LOCK_EX);
            }
        }

	        error_log("[AIAGENTNSFW] Scene processed: $sexSceneName | Actors: " . implode(",", $orderedActorList) . " | Primary: " . ($primaryPartner ?? "none") . " | Desc: $cleanedSceneDesc | FORCE_STOP=" . ($GLOBALS["AIAGENTNSFW_FORCE_STOP"] ? "Y" : "N"));
	
	        // Log the full event (with description) now that we have it computed
	        $sceneLogRequest = $GLOBALS["gameRequest"];
	        if ($sceneThreadTaggedLogText !== "") {
	            $sceneLogRequest[3] = $sceneThreadTaggedLogText;
	        }
	        logEvent($sceneLogRequest);

        // GROUP SCENE: give a non-primary participant a scene-aware turn so partners aren't silent while
        // the primary partner carries the dialogue. Cadence-gated (shared Global Speech Cooldown) + rotated,
        // so they chime in occasionally without flooding the main semaphore. Their stored scene context makes
        // prerequest inject scene-aware cues, so the line is about what's actually happening.
        if (_getNsfwSetting('GROUP_SCENE_PARTICIPANT_DIALOGUE', true)) {
            $playerNm = $GLOBALS["PLAYER_NAME"] ?? 'Player';
            $others = [];
            foreach ($orderedActorList as $a) {
                $a = trim((string)$a);
                if ($a === '' || strcasecmp($a, $playerNm) === 0) { continue; }
                if ($primaryPartner && strcasecmp($a, (string)$primaryPartner) === 0) { continue; }
                if (function_exists('aiagentNsfwIsProtectedNpc') && aiagentNsfwIsProtectedNpc($a)) { continue; }
                $others[] = $a;
            }
            if (!empty($others)) {
                // GROUP-SCENE CHATTER TICK (feature 2026-07-12): own interval slider; 0 = follow the
                // NPC Scene Global Speech Cooldown (pre-slider behavior, default 25s).
                $gsCadence = (int)_getNsfwSetting('GROUP_SCENE_TICK_SECONDS', 0);
                if ($gsCadence <= 0) { $gsCadence = (int)_getNsfwSetting('NPC_SCENE_GLOBAL_COOLDOWN_SECONDS', 25); }
                if ($gsCadence < 1) { $gsCadence = 1; }
                $gsClock = sys_get_temp_dir() . "/nsfw_groupscene_last.txt";
                if ((time() - (int)(@file_get_contents($gsClock) ?: 0)) >= $gsCadence) {
                    $gsIdxFile = sys_get_temp_dir() . "/nsfw_groupscene_idx.txt";
                    // RANDOM participant (user request 2026-07-12, was round-robin): orgy chime-ins pick
                    // a random non-primary participant; avoid the same NPC twice in a row when possible.
                    $gsIdx = (count($others) > 1) ? mt_rand(0, count($others) - 1) : 0;
                    $gsPrev = (int)(@file_get_contents($gsIdxFile) ?: -1);
                    if (count($others) > 1 && $gsIdx === $gsPrev) {
                        $gsIdx = ($gsIdx + 1) % count($others);
                    }
                    if (self::queueParticipantSceneTurn($others[$gsIdx], $primaryPartner ?: $playerNm)) {
                        @file_put_contents($gsClock, time(), LOCK_EX);
                        @file_put_contents($gsIdxFile, $gsIdx, LOCK_EX);
                    }
                }
            }
        }
    }

    /**
     * Handle ext_nsfw_npc_scene - NPC-to-NPC scene (no player)
     *
     * Sets up BOTH NPCs with full intimacy tracking, sex prompts, and tier prompts
     * just like player scenes. This allows NPCs to react to each other properly
     * during OStim NPCs mod scenes.
     */
    private static function handleNpcScene() {
        global $gameRequest;

        $gameRequest[3] = NsfwNpcScene::stripChimContextPrefix($gameRequest[3]);
        error_log("[AIAGENT-NSFW] Processing NPC-to-NPC scene: {$gameRequest[3]}");

        $npcSceneDialogueEnabled = _getNsfwSetting('NPC_SCENE_LLM_ENABLED', true);
        $npcSceneContextThrottle = max(1, (int)_getNsfwSetting('NPC_SCENE_CONTEXT_THROTTLE_SECONDS', 6));
        if (!$npcSceneDialogueEnabled) {
            $rawParts = explode('^', $gameRequest[3]);
            $rawThreadID = isset($rawParts[2]) ? preg_replace('/[^0-9-]/', '', (string)$rawParts[2]) : 'unknown';
            $contextThrottleFile = sys_get_temp_dir() . "/aiagent_nsfw_npc_scene_context_" . $rawThreadID . ".txt";
            $lastContextUpdate = (int)(@file_get_contents($contextThrottleFile) ?: 0);
            if ($lastContextUpdate > 0 && (time() - $lastContextUpdate) < $npcSceneContextThrottle) {
                error_log("[AIAGENT-NSFW] NPC scene context-only throttle: blocked thread {$rawThreadID}");
                $GLOBALS["gameRequest"][0] = "nsfw_blocked_policy";
                terminate();
                return;
            }
            @file_put_contents($contextThrottleFile, time());
        }

        $result = NsfwNpcScene::processNpcScene($gameRequest[3]);

        if (!$result) {
            error_log("[AIAGENT-NSFW] Failed to process NPC scene data");
            terminate();
            return;
        }

        $npc1Name = $result['npc1']['name'];
        $npc2Name = $result['npc2']['name'];
        $threadID = $result['threadID'];
        $sceneID = $result['sceneID'];
        $playerDistance = isset($result['playerDistance']) && is_numeric($result['playerDistance']) ? (float)$result['playerDistance'] : null;

        error_log("[AIAGENT-NSFW] NPC-to-NPC Scene: {$npc1Name} + {$npc2Name} (Thread: {$threadID}, Scene: {$sceneID}" . ($playerDistance !== null ? ", Distance: " . round($playerDistance) : "") . ")");

	        $sceneActors = is_array($result['actors'] ?? null) ? $result['actors'] : [$npc1Name, $npc2Name];
	        $actorRoles = is_array($result['actorRoles'] ?? null) ? $result['actorRoles'] : [];
	        $primaryPartners = is_array($result['primaryPartners'] ?? null) ? $result['primaryPartners'] : [];
	        $sceneThreadKey = aiagentNsfwSceneThreadKey('npc_scene', $threadID);
	        $GLOBALS["AIAGENTNSFW_SCENE_THREAD_KEY"] = $sceneThreadKey;

        // OStim NPC expansion can report the same NPC-only scene from both thread and subthread events.
        // Keep one canonical server pass per thread/scene so NPCs do not get duplicate tier prompts.
        $dedupKey = md5(strtolower(implode('|', $sceneActors) . '|' . $threadID . '|' . $sceneID));
        $dedupFile = sys_get_temp_dir() . "/aiagent_nsfw_npc_scene_" . preg_replace('/[^0-9a-z_-]/i', '_', (string)$threadID) . ".json";
        $lastScene = @json_decode((string)@file_get_contents($dedupFile), true);
        $isActorRoutedNpcSpeech = !empty($_GET["profile"])
            || (!empty($GLOBALS["HERIKA_NAME"]) && !nsfwIsNarratorName($GLOBALS["HERIKA_NAME"]));
        if (is_array($lastScene)
            && ($lastScene['key'] ?? '') === $dedupKey
            && (time() - (int)($lastScene['time'] ?? 0)) < 3
            && !$isActorRoutedNpcSpeech) {
            error_log("[AIAGENT-NSFW] Suppressed duplicate NPC scene route for thread {$threadID}, scene {$sceneID}");
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_duplicate";
            terminate();
            return;
        }
        @file_put_contents($dedupFile, json_encode(['key' => $dedupKey, 'time' => time()]));

        // Build actor list for NPC-to-NPC scene (no player). This may be multipart.
        $orderedActorList = $sceneActors;
        $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] = $orderedActorList;
        $GLOBALS["AIAGENTNSFW_RAW_SCENE_ACTOR_SLOTS"] = $orderedActorList;
        $GLOBALS["AIAGENTNSFW_ACTOR_ROLES"] = $actorRoles;
        $GLOBALS["AIAGENTNSFW_NPC_SCENE"] = true;  // Flag this as NPC-only scene
        $GLOBALS["AIAGENTNSFW_NPC_THREAD_ID"] = $threadID;
        $GLOBALS["AIAGENTNSFW_NPC_SCENE_PLAYER_DISTANCE"] = $playerDistance;

        // ============================================
        // SET UP BOTH NPCs WITH FULL INTIMACY TRACKING
        // ============================================
        // Mirror what handleSceneUpdate() does for player scenes
        // Each NPC gets: scene_actors, scene_phase, tier prompts, sex prompts
        // ============================================

        require_once __DIR__ . "/nsfw_data.php";
        require_once __DIR__ . "/nsfw_relationship.php";
        // NPC-to-NPC OStim scenes are speech/context routes. OStim NPCs owns mechanical
        // movement, so relationship affinity may flavor dialogue but must not block
        // speak-style injection or wait on player-scene accept/refuse tools.
        $npcAffinityGateEnabled = false;

        foreach ($orderedActorList as $actor) {
            $intimacyStatus = getIntimacyForActor($actor);

            // Check if this is a NEW scene or continuation. A different NPC scene thread must
            // rebuild the tier prompt even if stale state left scene_phase populated.
            $existingThreadID = isset($intimacyStatus["npc_scene_thread_id"]) ? intval($intimacyStatus["npc_scene_thread_id"]) : null;
            $expectedPartner = $primaryPartners[$actor] ?? NsfwNpcScene::resolvePrimaryPartnerForActor($actor, $orderedActorList, $sceneID);
            if ($expectedPartner === '') {
                $expectedPartner = ($actor === $npc1Name) ? $npc2Name : $npc1Name;
            }
            $existingPartner = trim((string)($intimacyStatus["npc_scene_partner"] ?? ""));
            $existingActors = is_array($intimacyStatus["scene_actors"] ?? null) ? $intimacyStatus["scene_actors"] : [];
            $existingActorKey = $existingActors;
            $currentActorKey = $orderedActorList;
            sort($existingActorKey, SORT_STRING);
            sort($currentActorKey, SORT_STRING);
            $isNewScene = !isset($intimacyStatus["scene_phase"])
                || $intimacyStatus["scene_phase"] === null
                || (!empty($intimacyStatus["is_npc_scene"]) && (
                    $existingThreadID !== $threadID
                    || strcasecmp($existingPartner, $expectedPartner) !== 0
                    || $existingActorKey !== $currentActorKey
                ));
            if ($isNewScene) {
                // NEW NPC SCENE: start directly in the accepted speech path. NPC
                // affinity remains available as context, but no accept/refuse tool
                // gate or arousal gate is used for NPC-only scenes.
                $intimacyStatus["level"] = 0;
                $intimacyStatus["sex_disposal"] = 10;
                $intimacyStatus["scene_phase"] = $npcAffinityGateEnabled ? "tier_prompt" : "accepted";
                $intimacyStatus["scene_is_idle"] = false;  // NPC scenes typically aren't idle
                $intimacyStatus["scene_start_time"] = time();
                $intimacyStatus["scene_actors"] = $orderedActorList;
                $intimacyStatus["raw_scene_actor_slots"] = $orderedActorList;
                $intimacyStatus["actor_roles"] = $actorRoles;
                $intimacyStatus["is_npc_scene"] = true;  // Mark as NPC-to-NPC scene
                $intimacyStatus["npc_scene_partner"] = $expectedPartner;
	                $intimacyStatus["npc_scene_thread_id"] = $threadID;
	                $intimacyStatus["npc_scene_id"] = $sceneID;
	                $intimacyStatus["last_npc_scene_update_time"] = time();
	                if ($playerDistance !== null) {
	                    $intimacyStatus["npc_scene_player_distance"] = $playerDistance;
	                }
                $intimacyStatus["npc_affinity_gate_disabled"] = !$npcAffinityGateEnabled;
                $intimacyStatus["accepted_sex"] = !$npcAffinityGateEnabled;
                $intimacyStatus["accepted_affection"] = false;
                $intimacyStatus["show_normal_kinks"] = !$npcAffinityGateEnabled;
                $intimacyStatus["show_secret_kinks"] = !$npcAffinityGateEnabled;
                $intimacyStatus["sex_started"] = !$npcAffinityGateEnabled;
                $intimacyStatus["had_sex_in_scene"] = !$npcAffinityGateEnabled;
                $intimacyStatus["tier_prompt_sent"] = null;
                $intimacyStatus["cached_tier_prompt"] = "";
                $intimacyStatus["pillow_talk_pending"] = false;
                $intimacyStatus["pillow_talk_prompt"] = "";
                $intimacyStatus["orgasmed"] = false;
                $intimacyStatus["refusal_expressed"] = false;
                $intimacyStatus["forced_scene"] = false;
                $intimacyStatus["request_scene_stop"] = false;
                $intimacyStatus["stop_command_sent"] = false;
                $intimacyStatus["last_scene_stop_time"] = null;
                $intimacyStatus["scene_stop_retry_count"] = 0;
                $intimacyStatus["is_transaction"] = false;
                $intimacyStatus["payment_confirmed"] = false;
                $intimacyStatus["service_completed"] = false;
                $intimacyStatus["current_primary_partner"] = $expectedPartner;
                $intimacyStatus["is_active_participant"] = false;
                $intimacyStatus["my_role_tags"] = $actorRoles[$actor] ?? [];
                $intimacyStatus["last_forced_refusal_scene_key"] = null;
                $intimacyStatus["last_refusal_speech_time"] = null;
                $intimacyStatus["last_refusal_speech_key"] = null;

                if ($npcAffinityGateEnabled) {
                    error_log("[AIAGENTNSFW] New NPC scene for $actor - starting tier_prompt phase (partner: {$intimacyStatus['npc_scene_partner']})");
                } else {
                    error_log("[AIAGENTNSFW] New NPC scene for $actor - NPC affinity gate disabled, starting accepted path (partner: {$intimacyStatus['npc_scene_partner']})");
                }

                // ============================================
                // DETERMINE NPC TYPE (PROSTITUTE, SLAVE, ETC)
                // ============================================
                $extendedData = NsfwNpcData::get($actor);

                $isSlave = isNpcSlave($actor);
                $isProstitute = isProstitute($actor);

                // Store canonical runtime keys for prerequest.php. Keep legacy mirrors only so stale
                // readers do not diverge, but do not let them become a separate source of truth.
                $intimacyStatus["npc_is_prostitute"] = $isProstitute;
                $intimacyStatus["npc_is_slave"] = $isSlave;
                $intimacyStatus["is_prostitute"] = $isProstitute;
                $intimacyStatus["is_slave"] = $isSlave;
	                $intimacyStatus["current_scene_desc"] = $result['sceneContext'] ?? ("NPC intimate scene: " . implode(' and ', $orderedActorList) . " in {$sceneID}");
	                $intimacyStatus["current_scene_name"] = $sceneID;
	                $intimacyStatus["current_scene_thread_key"] = $sceneThreadKey;

                // ============================================
                // GET TIER PROMPT FOR THIS NPC
                // ============================================
                // Use relationship between the two NPCs
                $partnerName = $intimacyStatus["npc_scene_partner"];
                // Get NPC-to-NPC relationship (not player relationship)
                $relationship = RelationshipManager::getRelationship($actor, $partnerName);
                $affinity = $relationship['aff'] ?? 0;
                $tier = strtolower($relationship['tier'] ?? 'neutral');

                // Get appropriate tier prompt
                $tierPrompt = $isSlave
                    ? NsfwRelationship::getSlaveTierPrompt($affinity, $partnerName)
                    : NsfwRelationship::getTierPromptByAffinity($affinity, $isProstitute, $partnerName, $actor, 'npc');
                $intimacyStatus["cached_tier_prompt"] = $tierPrompt;
                $intimacyStatus["partner_affinity"] = $affinity;
                $intimacyStatus["partner_tier"] = $tier;
                if (!$npcAffinityGateEnabled) {
                    $intimacyStatus["cached_tier_prompt"] = "";
                    $intimacyStatus["tier_prompt_sent"] = true;
                }

                error_log("[AIAGENTNSFW] NPC $actor tier prompt for partner $partnerName (affinity: $affinity, tier: $tier, prostitute: " . ($isProstitute ? 'YES' : 'NO') . ")");

                // ============================================
                // LOAD SEX PROMPT AND SPEAK STYLE
                // ============================================
                // These will be injected when the NPC speaks during the scene
                if (!empty($extendedData['sex_prompt']) || !empty($extendedData['nsfw_sex_prompt'])) {
                    $intimacyStatus["has_sex_prompt"] = true;
                    error_log("[AIAGENTNSFW] NPC $actor has configured sex prompt");
                }

                if (!empty($extendedData['sex_speech_style']) || !empty($extendedData['nsfw_speak_style'])) {
                    $intimacyStatus["has_speak_style"] = true;
                    error_log("[AIAGENTNSFW] NPC $actor has configured speak style");
                }

                // Save intimacy status for this NPC
                updateIntimacyForActor($actor, $intimacyStatus);
            } else {
                // RESPONSIVENESS: when the OStim/SexLab position/scene CHANGES mid-session, flush this actor's
                // un-spoken voice backlog so they immediately react to the NEW scene instead of draining stale
                // chatter from the old position. The new current_scene_desc is set below (so they mention the new
                // scene). Orgasm lines still fire naturally during the scene; the end-flush + pillow talk handle
                // the orgasm-then-exit case separately.
                $prevSceneNameForFlush = trim((string)($intimacyStatus["current_scene_name"] ?? ""));
                if ($prevSceneNameForFlush !== "" && strcasecmp($prevSceneNameForFlush, (string)$sceneID) !== 0 && isset($GLOBALS["db"])) {
                    $escapedFlushActor = $GLOBALS["db"]->escape($actor);
                    $GLOBALS["db"]->delete("eventlog", "type = 'chat' and delivery_state = 'emitted' and people like '%|$escapedFlushActor|%'");
                    error_log("[AIAGENTNSFW] NPC scene changed for $actor ({$prevSceneNameForFlush} -> {$sceneID}) - flushed stale voice backlog");
                }
                // CONTINUING SCENE: Update scene actors if needed
                if (empty($intimacyStatus["scene_actors"]) || $intimacyStatus["scene_actors"] !== $orderedActorList) {
                    $intimacyStatus["scene_actors"] = $orderedActorList;
                    $intimacyStatus["raw_scene_actor_slots"] = $orderedActorList;
                    $intimacyStatus["actor_roles"] = $actorRoles;
                    $intimacyStatus["is_npc_scene"] = true;
                }
                $intimacyStatus["is_npc_scene"] = true;
                $intimacyStatus["npc_scene_partner"] = $expectedPartner;
                $intimacyStatus["current_primary_partner"] = $intimacyStatus["npc_scene_partner"];
	                $intimacyStatus["npc_scene_thread_id"] = $threadID;
	                $intimacyStatus["npc_scene_id"] = $sceneID;
	                $intimacyStatus["last_npc_scene_update_time"] = time();
	                if ($playerDistance !== null) {
	                    $intimacyStatus["npc_scene_player_distance"] = $playerDistance;
	                }
	                $intimacyStatus["current_scene_desc"] = $result['sceneContext'] ?? ("NPC intimate scene: " . implode(' and ', $orderedActorList) . " in {$sceneID}");
	                $intimacyStatus["current_scene_name"] = $sceneID;
	                $intimacyStatus["current_scene_thread_key"] = $sceneThreadKey;
	                $intimacyStatus["my_role_tags"] = $actorRoles[$actor] ?? [];
                if (!$npcAffinityGateEnabled && !empty($intimacyStatus["is_npc_scene"])) {
                    $intimacyStatus["npc_affinity_gate_disabled"] = true;
                    $intimacyStatus["scene_phase"] = ($intimacyStatus["scene_phase"] === "engaged") ? "engaged" : "accepted";
                    $intimacyStatus["accepted_sex"] = true;
                    $intimacyStatus["show_normal_kinks"] = true;
                    $intimacyStatus["show_secret_kinks"] = true;
                    $intimacyStatus["sex_started"] = true;
                    $intimacyStatus["had_sex_in_scene"] = true;
                    $intimacyStatus["refusal_expressed"] = false;
                    $intimacyStatus["request_scene_stop"] = false;
                }
                updateIntimacyForActor($actor, $intimacyStatus);
                error_log("[AIAGENTNSFW] Continuing NPC scene for $actor (phase: {$intimacyStatus['scene_phase']})");
            }
        }

        // ============================================
        // BUILD SCENE CONTEXT FOR LOGGING
        // ============================================
        $npc1Affinity = $result['npc1']['affinity'] ?? 0;
        $npc2Affinity = $result['npc2']['affinity'] ?? 0;
        $npc1Tier = RelationshipManager::getTierLabel($npc1Affinity);
        $npc2Tier = RelationshipManager::getTierLabel($npc2Affinity);

	        $participantSummary = implode(', ', $orderedActorList);
	        $contextMsg = "NPC intimate scene started: {$participantSummary}. {$npc1Name} is {$npc1Tier} toward {$npc2Name}; {$npc2Name} is {$npc2Tier} toward {$npc1Name}";
	        error_log("[AIAGENTNSFW] $contextMsg");
	
	        aiagentNsfwSceneThreadUpsert($sceneThreadKey, 'npc_scene', $orderedActorList, $sceneID, $result['sceneContext'] ?? $contextMsg, 'ext_nsfw_npc_scene');
	        $npcSceneLogRequest = $GLOBALS["gameRequest"];
	        $npcSceneLogRequest[3] = aiagentNsfwSceneThreadTag($contextMsg, $sceneThreadKey);
	        logEvent($npcSceneLogRequest);
	        $GLOBALS["gameRequest"][3] = $contextMsg;

        if (!$npcSceneDialogueEnabled) {
            error_log("[AIAGENT-NSFW] NPC scene context-only mode: state updated, LLM dialogue suppressed");
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_policy";
            terminate();
            return;
        }

        // Don't terminate - let the request flow continue so NPCs can speak
        // The prerequest.php will inject their prompts when they respond
    }

    /**
     * Handle ext_nsfw_npc_invite - NPC-to-NPC invite phase
     * Fires BEFORE the scene starts when dom actor approaches sub actor
     * This is the perfect time to inject tier prompts based on relationship
     *
     * Data format: DomActor^SubActor^ThirdActor (third is optional)
     */
    private static function handleNpcInvite() {
        global $gameRequest;

        $gameRequest[3] = NsfwNpcScene::stripChimContextPrefix($gameRequest[3]);
        error_log("[AIAGENT-NSFW] NPC Invite phase: {$gameRequest[3]}");

        // Parse invite data (using ^ delimiter)
        $parts = explode('^', $gameRequest[3]);

        if (count($parts) < 2) {
            error_log("[AIAGENT-NSFW] Invalid invite data: {$gameRequest[3]}");
            terminate();
            return;
        }

        $domActor = NsfwNpcScene::cleanNpcName($parts[0]);
        $subActor = NsfwNpcScene::cleanNpcName($parts[1]);
        $thirdActor = isset($parts[2]) ? NsfwNpcScene::cleanNpcName($parts[2]) : null;

        error_log("[AIAGENT-NSFW] NPC Invite: {$domActor} -> {$subActor}" . ($thirdActor ? " + {$thirdActor}" : ""));

        // Build actor list
        $actorList = [$domActor, $subActor];
        if ($thirdActor) {
            $actorList[] = $thirdActor;
        }

        require_once __DIR__ . "/nsfw_relationship.php";
        require_once __DIR__ . "/nsfw_data.php";

        // Set up intimacy tracking for each NPC before the scene starts
        // This injects tier prompts based on their relationship with each other
        foreach ($actorList as $actor) {
            $intimacyStatus = getIntimacyForActor($actor);

            // Set invite phase - NPCs are approaching each other
            $intimacyStatus["level"] = 0;
            $intimacyStatus["scene_phase"] = "invite";
            $intimacyStatus["scene_actors"] = $actorList;
            $intimacyStatus["invite_time"] = time();
            $intimacyStatus["last_npc_scene_update_time"] = time();

            // Determine partner (the other actor)
            $partnerName = ($actor === $domActor) ? $subActor : $domActor;

            // Check NPC type (slave, prostitute)
            $isSlave = isNpcSlave($actor);
            $isProstitute = isProstitute($actor);

            $intimacyStatus["npc_is_slave"] = $isSlave;
            $intimacyStatus["npc_is_prostitute"] = $isProstitute;
            $intimacyStatus["is_npc_scene"] = true;
            $intimacyStatus["npc_partner"] = $partnerName;
            $intimacyStatus["npc_scene_partner"] = $partnerName;
            $intimacyStatus["current_primary_partner"] = $partnerName;
            $intimacyStatus["npc_affinity_gate_disabled"] = !isNpcAffinityGatingEnabled();
            $intimacyStatus["accepted_sex"] = false;
            $intimacyStatus["sex_started"] = false;
            $intimacyStatus["had_sex_in_scene"] = false;
            $intimacyStatus["npc_invite_role"] = ($actor === $domActor) ? "initiator" : "recipient";
            $intimacyStatus["npc_invite_initiator"] = $domActor;
            $intimacyStatus["npc_invite_target"] = $subActor;
            $intimacyStatus["current_scene_desc"] = "{$domActor} is walking toward or inviting {$subActor}. This is an NPC invite/walk-to phase; no sex scene has started yet.";

            error_log("[AIAGENT-NSFW] NPC Invite setup for {$actor}: partner={$partnerName}, slave=" . ($isSlave ? "YES" : "no") . ", prostitute=" . ($isProstitute ? "YES" : "no"));

            updateIntimacyForActor($actor, $intimacyStatus);
        }

        // Log the invite event for narrative purposes
        $contextMsg = "{$domActor} is approaching {$subActor} with romantic intent.";
        if ($thirdActor) {
            $contextMsg = "{$domActor} is approaching {$subActor} and {$thirdActor} with romantic intent.";
        }

        $GLOBALS["gameRequest"][3] = $contextMsg;
        logEvent($GLOBALS["gameRequest"]);

        if (!_getNsfwSetting('NPC_INVITE_LLM_ENABLED', false)) {
            error_log("[AIAGENT-NSFW] NPC invite context-only mode: state updated, invite LLM dialogue suppressed");
            $GLOBALS["gameRequest"][0] = "nsfw_blocked_policy";
            terminate();
            return;
        }

        error_log("[AIAGENT-NSFW] NPC Invite phase complete - tier prompts will inject when NPCs speak");
    }

    /**
     * Handle ext_nsfw_npc_orgasm - NPC orgasm in NPC-to-NPC scene
     * Data format: actorName^partnerName^sceneID
     */
    private static function handleNpcOrgasm() {
        global $gameRequest;

        $gameRequest[3] = NsfwNpcScene::stripChimContextPrefix($gameRequest[3]);
        error_log("[AIAGENT-NSFW] NPC Orgasm: {$gameRequest[3]}");

        // Parse orgasm data (using ^ delimiter)
        $parts = explode('^', $gameRequest[3]);

        if (count($parts) < 2) {
            error_log("[AIAGENT-NSFW] Invalid NPC orgasm data: {$gameRequest[3]}");
            terminate();
            return;
        }

        $orgasmedActor = NsfwNpcScene::cleanNpcName($parts[0]);
        $partnerName = NsfwNpcScene::cleanNpcName($parts[1]);
        $sceneID = isset($parts[2]) ? $parts[2] : '';
        $actionType = isset($parts[3]) ? $parts[3] : '';

        if ($partnerName === '' && $orgasmedActor !== '') {
            $orgasmIntimacy = getIntimacyForActor($orgasmedActor);
            $orgasmActors = is_array($orgasmIntimacy["raw_scene_actor_slots"] ?? null)
                ? $orgasmIntimacy["raw_scene_actor_slots"]
                : (is_array($orgasmIntimacy["scene_actors"] ?? null) ? $orgasmIntimacy["scene_actors"] : []);
            $resolveSceneID = trim((string)($sceneID ?: ($orgasmIntimacy["current_scene_name"] ?? ($orgasmIntimacy["npc_scene_id"] ?? ''))));
            $resolvedPartner = NsfwNpcScene::resolvePrimaryPartnerForActor($orgasmedActor, $orgasmActors, $resolveSceneID);
            if ($resolvedPartner !== '') {
                $partnerName = $resolvedPartner;
                $gameRequest[3] = "{$orgasmedActor}^{$partnerName}^{$sceneID}^{$actionType}";
                error_log("[AIAGENT-NSFW] NPC Orgasm repaired blank partner: {$orgasmedActor} -> {$partnerName}");
            }
        }

        error_log("[AIAGENT-NSFW] NPC Orgasm: {$orgasmedActor} climaxed with {$partnerName}");

        // Log the orgasm event with readable context, but PRESERVE gameRequest[3] (the ^ payload)
        // so prerequest.php's orgasm block can still parse orgasmer^partner^scene and name the REAL partner.
        // (Overwriting it here previously made the climaxing NPC name the player instead of the actual partner.)
        $logReq = $GLOBALS["gameRequest"];
        $logReq[3] = "{$orgasmedActor} is reaching climax with {$partnerName}.";
        logEvent($logReq);

        error_log("[AIAGENT-NSFW] NPC Orgasm logged for {$orgasmedActor}");
    }

    /**
     * SAFETY NET for the PLAYER route. OStim's ostim_end (-> chatnf_sl_end -> handleSceneEnd) can be lost:
     * the player walks off, the thread is torn down, or OStimEnd bails on no-valid-actors. When it is, the
     * player scene NEVER tears down - sex_started/scene_phase/current_scene_desc linger and the NPC keeps
     * narrating sex while just standing there (the "she won't shut up about sex outside the scene" bug).
     * NPC-to-NPC scenes already have a stale backstop; this is the player equivalent and it runs the SAME
     * full teardown chatnf_sl_end would: durable past-tense memory + chat strip + thread pull + pillow talk
     * + state reset. Fires when a player scene has had no tick for PLAYER_SCENE_STALE_SECONDS. Returns true
     * if it tore a scene down.
     */
    public static function endStalePlayerSceneIfNeeded($actor)
    {
        global $gameRequest;
        $actor = trim((string)$actor);
        if ($actor === '') { return false; }
        $playerName = $GLOBALS['PLAYER_NAME'] ?? 'Player';
        if (strcasecmp($actor, $playerName) === 0) { return false; }
        if ($actor === 'The Narrator' || $actor === 'Character') { return false; }

        $intimacy = getIntimacyForActor($actor);
        if (!is_array($intimacy) || empty($intimacy)) { return false; }
        // NPC-to-NPC scenes have their own stale net; this is the PLAYER route only.
        if (!empty($intimacy['is_npc_scene'])) { return false; }
        // Pillow talk already queued means handleSceneEnd already ran - don't double-tear-down.
        if (!empty($intimacy['pillow_talk_pending'])) { return false; }

        $phase = strtolower(trim((string)($intimacy['scene_phase'] ?? '')));
        $hasActiveScene = !empty($intimacy['sex_started'])
            || (int)($intimacy['level'] ?? 0) > 0
            || !empty($intimacy['current_scene_desc'])
            || !empty($intimacy['current_scene_name'])
            || in_array($phase, ['tier_prompt', 'accepted', 'engaged'], true);
        if (!$hasActiveScene) { return false; }

        $threshold = function_exists('_getNsfwSetting') ? (int)_getNsfwSetting('PLAYER_SCENE_STALE_SECONDS', 60) : 60;
        $threshold = max(20, $threshold);
        $lastTouch = max(
            (int)($intimacy['last_scene_update_time'] ?? 0),
            (int)($intimacy['scene_start_time'] ?? 0)
        );
        // No heartbeat at all -> cannot prove it is stale; leave it (avoid killing a just-started scene).
        if ($lastTouch <= 0) { return false; }
        if ((time() - $lastTouch) < $threshold) { return false; }

        error_log("[AIAGENT_NSFW] Stale PLAYER scene for {$actor} (" . (time() - $lastTouch) . "s since last tick, threshold {$threshold}s) - forcing full chatnf_sl_end teardown");

        // Reuse handleSceneEnd verbatim: it keys off HERIKA_NAME (= $actor here) + the scoring roster in
        // gameRequest[3]. Synthesize the roster from the stored scene actors (fall back to actor + player).
        $sceneActors = (is_array($intimacy['scene_actors'] ?? null) && !empty($intimacy['scene_actors']))
            ? $intimacy['scene_actors']
            : [$actor, $playerName];
        $scoring = 'stale';
        foreach ($sceneActors as $sa) {
            $sa = trim((string)$sa);
            if ($sa !== '') { $scoring .= '/' . $sa . '@100'; }
        }

        $savedReq = $gameRequest;
        $savedForceStop = array_key_exists('AIAGENTNSFW_FORCE_STOP', $GLOBALS) ? $GLOBALS['AIAGENTNSFW_FORCE_STOP'] : null;
        $gameRequest[3] = $scoring;
        self::handleSceneEnd();
        // handleSceneEnd's PROMPTS['chatnf_sl_end'] / FORCE_STOP target a real scene-end TURN; this is a
        // normal player turn, so restore the request and the prior suppression flag. Pillow talk still gets
        // delivered by prerequest's existing pillow_talk_pending latch off the reloaded intimacy.
        $gameRequest = $savedReq;
        if ($savedForceStop === null) {
            unset($GLOBALS['AIAGENTNSFW_FORCE_STOP']);
        } else {
            $GLOBALS['AIAGENTNSFW_FORCE_STOP'] = $savedForceStop;
        }
        return true;
    }

    /**
     * Handle chatnf_sl_end - Scene ended
     * Injects pillow talk prompts to ALL NPCs who were in the scene
     */
    private static function handleSceneEnd() {
        global $gameRequest;

        error_log("[AIAGENT_NSFW] Scene End: {$gameRequest[3]}");

        $sceneResultParts = explode("/", $gameRequest[3]);
        $scoringPart = array_values(array_filter(array_slice($sceneResultParts, 1), function($part) {
            return trim((string)$part) !== '';
        }));
        $scoring = [];
        $scoringActors = [];
        foreach ($scoringPart as $part) {
            $actorResult = explode("@", $part, 2);
            $actorName = trim((string)($actorResult[0] ?? ''));
            if ($actorName === '') {
                continue;
            }
            $score = $actorResult[1] ?? 'unknown';
            $scoringActors[] = $actorName;
            $scoring[] = $actorName . " satisfaction score: " . $score;
        }

        $actor = $GLOBALS["HERIKA_NAME"];
        $currentIntimacy = getIntimacyForActor($actor);
        $playerName = $GLOBALS['PLAYER_NAME'] ?? 'Player';

        // Get all actors who were in the scene BEFORE we reset their intimacy.
        // CROSS-SCENE FIX: the chatnf_sl_end SCORING is the PSC-authoritative list of who actually ended.
        // A scene-end can be ADDRESSED to a bystander listener (HERIKA_NAME) who is in a DIFFERENT, still-running
        // scene - keying off HERIKA_NAME's own scene_actors then ends the WRONG scene (observed: a Dorian/Eris
        // end addressed to one participant killed her live NPC-to-NPC scene, which then got misread as a player scene and
        // triggered a "player initiated sex" refusal). So prefer the scoring; expand each scored actor to their
        // stored roster; only fall back to HERIKA_NAME's scene when there is no scoring at all.
        $sceneActors = [];
        if (!empty($scoringActors)) {
            foreach ($scoringActors as $scoreActor) {
                $scoreActorIntimacy = getIntimacyForActor($scoreActor);
                if (!empty($scoreActorIntimacy["scene_actors"]) && is_array($scoreActorIntimacy["scene_actors"])) {
                    $sceneActors = array_values(array_unique(array_merge($sceneActors, $scoreActorIntimacy["scene_actors"])));
                }
            }
            if (empty($sceneActors)) {
                $sceneActors = array_values(array_unique($scoringActors));
            }
            error_log("[AIAGENT_NSFW] Scene end actors from scoring (authoritative): " . implode(", ", $sceneActors));
        } else if (!empty($currentIntimacy["scene_actors"])) {
            $sceneActors = $currentIntimacy["scene_actors"];
        } else if (isset($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"])) {
            $sceneActors = $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"];
        }
	        error_log("[AIAGENT_NSFW] Scene ended - actors in scene: " . implode(", ", $sceneActors));
	        // Snap this scene's SPOKEN chat out of future model context (the "won't shut up about the scene
	        // after it's over" fix). Tag the scene actors' lines from this scene's window with an immediately
	        // inactive key so the context cleaner strips them next turn. Record stays in eventlog.
	        if (!empty($sceneActors)) {
	            $sceneOverKey = 'over_' . substr(md5(implode('|', $sceneActors) . '_' . time()), 0, 12);
	            $sceneWindowStart = (int)($currentIntimacy["scene_start_time"] ?? 0);
	            if ($sceneWindowStart <= 0) { $sceneWindowStart = time() - 1800; }
	            $sceneChatTagged = aiagentNsfwTagSceneChatForActors($sceneActors, $sceneOverKey, $sceneWindowStart);
	            error_log("[AIAGENT_NSFW] Tagged scene chat for context suppression: {$sceneChatTagged} actor(s), key {$sceneOverKey}, since {$sceneWindowStart}");
	        }
	        // MEMORY: the chat-suppression above strips the verbatim scene dialogue so NPCs stop NARRATING the
	        // scene - but that also wiped their MEMORY that it happened (NPC denied an affair she'd just had).
	        // Leave a durable, PAST-TENSE factual record (type 'infoaction', NOT 'chat', so the cleaner keeps it)
	        // scoped to the scene actors, so they remember the encounter without continuing it. Only when real sex
	        // occurred (not affection-only poses).
	        if (!empty($sceneActors)) {
	            $sceneHadSex = false;
	            foreach ($sceneActors as $memCheckActor) {
	                if (strcasecmp($memCheckActor, $playerName) === 0) { continue; }
	                $memCheck = getIntimacyForActor($memCheckActor);
	                if (!empty($memCheck["had_sex_in_scene"]) || !empty($memCheck["sex_started"]) || !empty($memCheck["orgasmed"])) {
	                    $sceneHadSex = true;
	                    break;
	                }
	            }
	            if ($sceneHadSex && function_exists('logEvent')) {
	                $memTs   = (int)($GLOBALS["gameRequest"][1] ?? time());
	                $memGts  = (float)($GLOBALS["gameRequest"][2] ?? 0);
	                $memText = "(" . implode(" and ", $sceneActors) . " just had sex.)";
	                $memPeople = "|" . implode("|", $sceneActors) . "|";
	                logEvent(["infoaction", $memTs, $memGts, $memText], $memPeople);
	                error_log("[AIAGENT_NSFW] Logged durable scene memory: {$memText}");
	            }
	        }
	        // AFFECTION MEMORY: the sex memory above only fires when real sex occurred; an affection-only scene
        // (hug/kiss/cuddle, Tier 1/2) gets its chat suppressed but no memory, so she would FORGET the kiss.
        // Leave a soft past-tense record for a genuine (non-refused) affection encounter so she remembers it
        // without continuing it. Skips scenes that had real sex (already memorialized) or were refused.
        if (!empty($sceneActors) && function_exists('logEvent')) {
            $affHadSex = false; $affHappened = false; $affRefused = false;
            foreach ($sceneActors as $affActor) {
                if (strcasecmp($affActor, $playerName) === 0) { continue; }
                $affChk = getIntimacyForActor($affActor);
                if (!empty($affChk["had_sex_in_scene"]) || !empty($affChk["sex_started"]) || !empty($affChk["orgasmed"])) { $affHadSex = true; }
                if (($affChk["scene_phase"] ?? null) === "rejected" || !empty($affChk["refusal_expressed"])) { $affRefused = true; }
                $affTier = (int)($affChk["intensity_tier"] ?? 0);
                if ($affTier >= 1 && $affTier <= 2) { $affHappened = true; }
            }
            if ($affHappened && !$affHadSex && !$affRefused) {
                $affTs  = (int)($GLOBALS["gameRequest"][1] ?? time());
                $affGts = (float)($GLOBALS["gameRequest"][2] ?? 0);
                $affText = "(" . implode(" and ", $sceneActors) . " shared an affectionate, intimate moment together.)";
                logEvent(["infoaction", $affTs, $affGts, $affText], "|" . implode("|", $sceneActors) . "|");
                error_log("[AIAGENT_NSFW] Logged durable affection memory: {$affText}");
            }
        }
        $endedThreadCount = aiagentNsfwSceneThreadEndByActors($sceneActors);
	        if ($endedThreadCount > 0) {
	            error_log("[AIAGENT_NSFW] Cleared {$endedThreadCount} active Sharmat scene thread(s) for ended scene");
	        }
	
	        // ============================================
        // PURGE SEX EVENT BACKLOG — scene is over, nothing queued should fire
        // ============================================
        if (isset($GLOBALS["db"]) && !empty($sceneActors)) {
	            $sexTypes = "'chatnf_sl','chatnf_sl_moan','chatnf_sl_naked','chatnf_sl_climax',"
	                      . "'ext_nsfw_sexcene','ext_nsfw_orgasm','ext_nsfw_action','ext_nsfw_scene',"
	                      . "'chatnf_npc_sl','ext_nsfw_npc_scene','ext_nsfw_npc_invite','ext_nsfw_npc_orgasm',"
	                      . "'prechat','rechat','narration'";
            foreach ($sceneActors as $clearActor) {
                if (strcasecmp($clearActor, $playerName) === 0) continue;
                $escapedActor = $GLOBALS["db"]->escape($clearActor);
                $GLOBALS["db"]->delete("eventlog",
                    "type in ($sexTypes) and people like '%|$escapedActor|%'"
                );
                // FLUSH THE SPOKEN VOICE BACKLOG: the generated scene lines (type 'chat', not yet spoken) are
                // what kept the NPCs reciting scene chatter after the scene ended. Drop the un-spoken backlog for
                // each scene actor so the audio queue empties immediately. Scoped to the scene actors (the Sharmat
                // threaded scene), not the wider chat context. Pillow talk is generated AFTER this, so it survives.
                $GLOBALS["db"]->delete("eventlog",
                    "type = 'chat' and delivery_state = 'emitted' and people like '%|$escapedActor|%'"
                );
            }
            error_log("[AIAGENT_NSFW] Purged sex event backlog + un-spoken voice backlog for all scene actors");
        }
        // Clear scene-active marker and dedup hash. Keep the scene-ended latch briefly so
        // delayed scene-stage requests cannot reignite sex talk after pillow talk begins.
        @unlink(sys_get_temp_dir() . "/nsfw_scene_active.txt");
        @unlink(sys_get_temp_dir() . "/nsfw_player_scene_active.txt");
        @unlink(sys_get_temp_dir() . "/nsfw_scene_last_hash.txt");
        foreach (glob(sys_get_temp_dir() . "/nsfw_scene_speech_*.txt") ?: [] as $sceneSpeechClock) {
            @unlink($sceneSpeechClock);
        }
        $sceneEndedTime = time();
        @file_put_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt", $sceneEndedTime);
        // Stamp the PLAYER-specific ended marker ONLY when the player was actually in this scene.
        // The player-scene kill switch (scene_threads.php) reads this marker, so an NPC-to-NPC
        // scene ending here must NOT touch it - otherwise it would falsely cancel the player's
        // concurrent live scene and null its scene_phase mid-orgasm ("she never consented").
        $playerWasInScene = false;
        foreach ($sceneActors as $endCheckActor) {
            if (strcasecmp((string)$endCheckActor, (string)$playerName) === 0) { $playerWasInScene = true; break; }
        }
        if ($playerWasInScene) {
            @file_put_contents(sys_get_temp_dir() . "/nsfw_player_scene_ended.txt", $sceneEndedTime);
        }
        @file_put_contents(sys_get_temp_dir() . "/nsfw_scene_ended_meta.json", json_encode([
            'time' => $sceneEndedTime,
            'gamets' => (float)($gameRequest[2] ?? 0),
        ]));

        // REJECTION CLEAR ON SCENE EXIT (OStim + SexLab, via chatnf_sl_end): the engine scene is actually over, so
        // wipe the ENTIRE refusal/rejection state for every scene actor. Otherwise a 'rejected' phase + the
        // <refusal_instruction>/<non_consent> cues carry into the NEXT scene and lock the NPC into refusing again
        // (the "she started a scene and it immediately exited" loop). A fresh scene must be a clean slate.
        foreach ($sceneActors as $clearRefuseActor) {
            if (strcasecmp($clearRefuseActor, $playerName) === 0) continue;
            $refuseReset = getIntimacyForActor($clearRefuseActor);
            $wasRejected = (($refuseReset["scene_phase"] ?? null) === "rejected")
                || !empty($refuseReset["refusal_expressed"]) || !empty($refuseReset["forced_scene"])
                || !empty($refuseReset["refused_until_scene_end"]);
            $refuseReset["refused_until_scene_end"] = false;
            $refuseReset["refusal_expressed"] = false;
            $refuseReset["forced_scene"] = false;
            $refuseReset["request_scene_stop"] = false;
            $refuseReset["stop_command_sent"] = false;
            $refuseReset["npc_refusal_dialogue_only"] = false;
            $refuseReset["last_refusal_speech_key"] = null;
            $refuseReset["last_refusal_speech_time"] = null;
            $refuseReset["last_forced_refusal_scene_key"] = null;
            if (($refuseReset["scene_phase"] ?? null) === "rejected") {
                $refuseReset["scene_phase"] = null;
            }
            updateIntimacyForActor($clearRefuseActor, $refuseReset);
            if ($wasRejected) {
                error_log("[AIAGENT_NSFW] Cleared refusal/rejection state for {$clearRefuseActor} (scene ended - clean slate)");
            }
        }

        // Reset response counters for all scene actors (cooldown system)
        if (function_exists('apcu_delete')) {
            foreach ($sceneActors as $sceneActor) {
                apcu_delete("nsfw_responses_{$sceneActor}");
                apcu_delete("nsfw_cooldown_sex_{$sceneActor}");
                apcu_delete("nsfw_cooldown_climax_{$sceneActor}");
            }
            error_log("[AIAGENT_NSFW] Reset response counters for scene actors");
        }

        // Check if this was a prostitute transaction
        $wasTransaction = !empty($currentIntimacy["is_transaction"]);
        $paymentConfirmed = !empty($currentIntimacy["payment_confirmed"]);
        $serviceCompleted = !empty($currentIntimacy["service_completed"]);

        // Detect if this was an NPC-to-NPC scene (no player involved)
        $isNpcOnlyScene = !empty($sceneActors);
        foreach ($sceneActors as $checkActor) {
            if (strcasecmp($checkActor, $playerName) === 0 || strcasecmp($checkActor, "Player") === 0) {
                $isNpcOnlyScene = false;
                break;
            }
        }

        if ($isNpcOnlyScene) {
            error_log("[AIAGENT_NSFW] NPC-to-NPC scene ended - setting up pillow talk for both NPCs");
        }

        // Inject pillow talk prompt to NPCs in the scene
        // When arousal gating ON: only NPCs who orgasmed get pillow talk
        // When arousal gating OFF: all NPCs in scene get pillow talk (intimacy status is NULL)
        $arousalGatingOn = isSexDisposalEnabled();

        $npcPillowQueue = [];
        foreach ($sceneActors as $sceneActor) {
            // Skip player
            if (strcasecmp($sceneActor, $playerName) === 0 || strcasecmp($sceneActor, "Player") === 0) {
                continue;
            }

            $actorIntimacy = getIntimacyForActor($sceneActor);

            // Pillow talk is session-based, not final-stage-based. If sex happened and the
            // scene de-escalated back to standing before ending, keep pillow talk. If this
            // was only standing/affection/romance, do not let chatnf_sl_end improvise aftercare.
            $acceptedPillowContext = !empty($actorIntimacy["accepted_sex"])
                || !empty($actorIntimacy["npc_is_slave"])
                || (!empty($actorIntimacy["npc_is_prostitute"]) && !empty($actorIntimacy["payment_confirmed"]))
                || (!empty($actorIntimacy["is_npc_scene"]) && !empty($actorIntimacy["npc_affinity_gate_disabled"]));
            $hadAcceptedSexInScene = !empty($actorIntimacy["had_sex_in_scene"])
                || (!empty($actorIntimacy["sex_started"]) && $acceptedPillowContext)
                || (!empty($actorIntimacy["orgasmed"]) && $acceptedPillowContext);
            if (!$hadAcceptedSexInScene) {
                error_log("[AIAGENT_NSFW] Skipping pillow talk for $sceneActor - no accepted sex occurred in this OStim session");
                continue;
            }

            // Pillow talk only after an orgasm (owner rule), regardless of arousal gating.
            $actorOrgasmed = !empty($actorIntimacy["orgasmed"]);
            if (!$actorOrgasmed) {
                error_log("[AIAGENT_NSFW] Skipping pillow talk for $sceneActor - no orgasm occurred");
                continue;
            }

            $actorWasTransaction = !empty($actorIntimacy["is_transaction"]);
            $actorPaymentConfirmed = !empty($actorIntimacy["payment_confirmed"]);
            $wasNpcScene = !empty($actorIntimacy["is_npc_scene"]);
            $npcScenePartner = $actorIntimacy["npc_scene_partner"] ?? null;

            // Build pillow talk prompt for this actor
            $pillowTalkPrompt = "";

            if ($wasNpcScene && $npcScenePartner) {
                // ============================================
                // NPC-TO-NPC SCENE PILLOW TALK
                // ============================================
                // NPCs talk to each other, not to player
                $partnerAffinity = $actorIntimacy["partner_affinity"] ?? 0;
                $partnerTier = $actorIntimacy["partner_tier"] ?? 'neutral';

                require_once __DIR__ . '/nsfw_data.php';
                $extended = NsfwNpcData::get($sceneActor);

                // Check for custom speak style pillow talk first
                if (!empty($extended['sex_speech_style'])) {
                    $speakStyle = NsfwData::getSpeakStyle($extended['sex_speech_style']);
                    if (!empty($speakStyle['pillow_talk_prompt'])) {
                        $pillowTalkPrompt = aiagentNsfwResolveSpeakStylePlaceholders(
                            $speakStyle['pillow_talk_prompt'],
                            $sceneActor,
                            $actorIntimacy,
                            ['is_npc_scene' => true, 'npc_partner' => $npcScenePartner, 'primary_partner' => $npcScenePartner]
                        );
                    }
                }

                // Fallback to tier-based pillow talk - reuse existing tier system
                // This checks for marriage/affair scenarios: if sceneActor is married
                // and partner != spouse, it's an affair; if partner == spouse, it's marriage
                if (empty($pillowTalkPrompt)) {
                    // Check if NPC is prostitute for tier prompt selection
                    $isProstitute = !empty($extended['is_prostitute']) || !empty($extended['profession_prostitute']);

                    // Build pillow talk using the tier system - handles marriage/affair/regular
                    $tierContext = NsfwRelationship::getTierPromptByAffinity(
                        $partnerAffinity,
                        $isProstitute,
                        $npcScenePartner,
                        $sceneActor,       // NPC name for marriage/affair detection
                        'npc'
                    );

                    // Wrap it in post-scene context
                    $pillowTalkPrompt = "The intimate scene has ended. $tierContext Share a brief post-intimacy response to $npcScenePartner.";
                }

                error_log("[AIAGENT_NSFW] NPC-to-NPC pillow talk for $sceneActor toward $npcScenePartner (tier: $partnerTier, affinity: $partnerAffinity)");

            } else if (isNpcSlave($sceneActor)) {
                // Slave pillow talk
                try {
                    $relationship = RelationshipManager::getPlayerRelationship($sceneActor);
                    $slaveAffinity = $relationship['aff'] ?? 0;
                    $pillowTalkPrompt = NsfwRelationship::getSlavePillowTalkPrompt($slaveAffinity, $playerName);
                    error_log("[AIAGENT_NSFW] Pillow talk for SLAVE $sceneActor (affinity: $slaveAffinity)");
                } catch (Exception $e) {
                    $pillowTalkPrompt = "The intimate moment has ended. As a slave, await further instructions.";
                    error_log("[AIAGENT_NSFW] Slave pillow talk fallback for $sceneActor: " . $e->getMessage());
                }
            } else if ($actorWasTransaction) {
                // Prostitute post-service
                if ($actorPaymentConfirmed) {
                    $pillowTalkPrompt = "The service session has ended. You were paid. Give a brief professional farewell.";
                } else {
                    $pillowTalkPrompt = "The service session ended but payment was NOT confirmed. You may remind about payment.";
                }
                error_log("[AIAGENT_NSFW] Post-service talk for prostitute $sceneActor (paid: " . ($actorPaymentConfirmed ? 'yes' : 'no') . ")");
            } else {
                // Regular NPC pillow talk (with player) - check for speak style pillow_talk_prompt
                // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
                require_once __DIR__ . '/nsfw_data.php';
                $extended = NsfwNpcData::get($sceneActor);
                error_log("[AIAGENT_NSFW] PILLOW DEBUG: $sceneActor extended_data keys: " . implode(', ', array_keys($extended)));
                error_log("[AIAGENT_NSFW] PILLOW DEBUG: sex_speech_style = " . ($extended['sex_speech_style'] ?? 'NOT SET'));

                if (!empty($extended['sex_speech_style'])) {
                    // Use NsfwData::getSpeakStyle which reads from JSONB
                    $speakStyle = NsfwData::getSpeakStyle($extended['sex_speech_style']);
                    error_log("[AIAGENT_NSFW] PILLOW DEBUG: speakStyle keys: " . ($speakStyle ? implode(', ', array_keys($speakStyle)) : 'NULL'));
                    error_log("[AIAGENT_NSFW] PILLOW DEBUG: pillow_talk_prompt = " . ($speakStyle['pillow_talk_prompt'] ?? 'NOT SET'));

                    if (!empty($speakStyle['pillow_talk_prompt'])) {
                        $pillowTalkPrompt = aiagentNsfwResolveSpeakStylePlaceholders($speakStyle['pillow_talk_prompt'], $sceneActor, $actorIntimacy);
                        error_log("[AIAGENT_NSFW] Using speak style pillow talk for $sceneActor (style: {$extended['sex_speech_style']})");
                    } else {
                        error_log("[AIAGENT_NSFW] PILLOW DEBUG: Style '{$extended['sex_speech_style']}' has NO pillow_talk_prompt!");
                    }
                } else {
                    error_log("[AIAGENT_NSFW] PILLOW DEBUG: $sceneActor has NO sex_speech_style set!");
                }

                if (empty($pillowTalkPrompt)) {
                    $pillowTalkPrompt = "The intimate moment has ended. Share a brief, genuine post-intimacy reaction in character.";
                    error_log("[AIAGENT_NSFW] Pillow talk for regular NPC $sceneActor (FALLBACK - no style pillow talk found)");
                } else {
                    error_log("[AIAGENT_NSFW] Pillow talk for regular NPC $sceneActor (USING SPEAK STYLE)");
                }
            }

            // Store pillow talk prompt for this actor so prerequest can inject it
            $actorIntimacy["pillow_talk_pending"] = true;
            $actorIntimacy["pillow_talk_prompt"] = $pillowTalkPrompt;
            updateIntimacyForActor($sceneActor, $actorIntimacy);

            // NPC-to-NPC: remember who needs a server-initiated turn to speak their own line.
            if ($wasNpcScene && !empty($npcScenePartner)) {
                $npcPillowQueue[$sceneActor] = $npcScenePartner;
            }
        }

        // Now reset scene state for actors from scoring
        $resetActors = array_values(array_unique(array_merge($sceneActors, $scoringActors)));
        $actorInEndedScene = false;
        foreach ($resetActors as $endedActor) {
            if (strcasecmp(trim((string)$endedActor), $actor) === 0) {
                $actorInEndedScene = true;
                break;
            }
        }

        foreach ($resetActors as $actorName) {
            $actorName = trim((string)$actorName);
            if ($actorName === '') {
                continue;
            }

            // Get current intimacy to preserve pillow talk
            $actorIntimacy = getIntimacyForActor($actorName);
            $pillowTalkPending = $actorIntimacy["pillow_talk_pending"] ?? false;
            $pillowTalkPrompt = $actorIntimacy["pillow_talk_prompt"] ?? "";

            // Reset scene state but keep pillow talk
            updateIntimacyForActor($actorName, [
                "level" => 0,
                "sex_disposal" => 10,
                "orgasmed" => false,
                "sex_started" => false,  // CRITICAL: Stop sex prompts from firing
                "is_naked" => 0,  // Reset clothing state - NPC is dressed after scene
                "scene_phase" => null,
                "tier_prompt_sent" => null,
	                "standing_intro_shown_thread" => null,
	                "cached_tier_prompt" => "",
	                "current_scene_desc" => null,
	                "current_scene_thread_key" => null,
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
                "refused_until_scene_end" => false,   // MUST clear on scene exit - was merged-through and kicked the next scene
                "npc_refusal_dialogue_only" => false,
                "scene_is_idle" => null,
                "scene_start_time" => null,
                "scene_actors" => null,
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
                // Clear NPC-to-NPC scene flags
                "is_npc_scene" => false,
                "npc_scene_partner" => null,
                "npc_scene_thread_id" => null,
                "npc_affinity_gate_disabled" => false,
                "partner_affinity" => null,
                "partner_tier" => null,
                "pillow_talk_pending" => $pillowTalkPending,
                "pillow_talk_prompt" => $pillowTalkPrompt
            ]);
        }

        // Also reset for current actor (preserve pillow talk)
        $pillowTalkPending = $actorInEndedScene ? ($currentIntimacy["pillow_talk_pending"] ?? false) : false;
        $pillowTalkPrompt = $actorInEndedScene ? ($currentIntimacy["pillow_talk_prompt"] ?? "") : "";
        updateIntimacyForActor($actor, [
            "level" => 0,
            "sex_disposal" => 10,
            "orgasmed" => false,
            "sex_started" => false,  // CRITICAL: Stop sex prompts from firing
            "is_naked" => 0,  // Reset clothing state - NPC is dressed after scene
            "scene_phase" => null,
            "tier_prompt_sent" => null,
	            "standing_intro_shown_thread" => null,
	            "cached_tier_prompt" => "",
	            "current_scene_desc" => null,
	            "current_scene_thread_key" => null,
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
            // Clear NPC-to-NPC scene flags
            "is_npc_scene" => false,
            "npc_scene_partner" => null,
            "npc_scene_thread_id" => null,
            "npc_affinity_gate_disabled" => false,
            "partner_affinity" => null,
            "partner_tier" => null,
            "pillow_talk_pending" => $pillowTalkPending,
            "pillow_talk_prompt" => $pillowTalkPrompt
        ]);

        // ============================================
        // POST-SCENE PROMPT FOR CURRENT ACTOR
        // ============================================
        // This uses the pillow_talk_prompt that was ALREADY set by the loop above (726-848)
        // The loop handles: arousal gating, orgasm check, speak styles, slave/prostitute/regular NPC
        // We just read back what was stored - ONE code path for pillow talk logic
        // ============================================

        // Re-read current actor's intimacy to get the pillow talk we just stored
        $finalIntimacy = getIntimacyForActor($actor);
        $postScenePrompt = "";

            if (!$actorInEndedScene) {
                $postScenePrompt = "The scene has ended.";
                $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
                error_log("[AIAGENT_NSFW] Post-scene for $actor: not a participant in ended scene; suppressing LLM response");
	        } else if (!empty($finalIntimacy["pillow_talk_prompt"])) {
	            // Use the pillow talk from the loop (respects arousal gating + orgasm check)
	            $postScenePrompt = $finalIntimacy["pillow_talk_prompt"];
	            error_log("[AIAGENT_NSFW] Post-scene for $actor: using stored pillow talk");
	        } else {
	            // No pillow talk was set - do not run an LLM turn that can improvise fake aftercare.
	            $postScenePrompt = "The scene has ended.";
	            $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
	            error_log("[AIAGENT_NSFW] Post-scene for $actor: no pillow talk earned; suppressing LLM response");
	        }

        $GLOBALS["PROMPTS"]["chatnf_sl_end"]["player_request"] = ["Scene Event: " . implode(",", $scoring) . "\n#Post-Scene Guidance: " . $postScenePrompt];
        $GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"] = false;
        $GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"] = "";

        // NPC-to-NPC scene: each participant speaks their OWN pillow-talk line in their own voice.
        // The chatnf_sl_end turn above only voices the current actor (if a participant); every other
        // participant gets a server-initiated turn so their one-shot latch fires. Player scenes never
        // populate $npcPillowQueue, so they are unaffected.
        if (!empty($npcPillowQueue)) {
            $normalPathActor = ($actorInEndedScene && empty($GLOBALS["AIAGENTNSFW_FORCE_STOP"])) ? $actor : null;
            foreach ($npcPillowQueue as $pillowNpc => $pillowPartner) {
                if ($normalPathActor !== null && strcasecmp((string)$pillowNpc, (string)$normalPathActor) === 0) {
                    continue; // this NPC already speaks via the chatnf_sl_end turn
                }
                self::queueNpcPillowTalkTurn($pillowNpc, $pillowPartner);
            }
        }
    }

    // Queue a server-initiated dialogue turn so a specific NPC speaks their OWN pillow-talk line in
    // their own voice after an NPC-to-NPC scene. Reuses the responselog rolecommand primitive the
    // director uses; the game polls it and routes a turn to that NPC, where prerequest.php's
    // pillow_talk_pending latch fires once. De-dupes against an already-queued unsent turn.
    private static function queueNpcPillowTalkTurn($npc, $partner) {
        if (!isset($GLOBALS["db"])) {
            return false;
        }
        $npc = trim(str_replace(['@', '|'], ' ', (string)$npc));
        $partner = trim(str_replace(['@', '|'], ' ', (string)$partner));
        if ($npc === '') {
            return false;
        }
        $escNpc = $GLOBALS["db"]->escape($npc);
        $dupe = $GLOBALS["db"]->fetchOne(
            "SELECT localts FROM responselog WHERE sent=0 AND tag='aiagent_nsfw_pillowtalk'"
            . " AND action LIKE 'rolecommand|Instruction@{$escNpc}@%' AND localts > " . (time() - 120) . " LIMIT 1"
        );
        if (!empty($dupe)) {
            return false;
        }
        $taskId = uniqid();
        $partnerRef = ($partner !== '') ? $partner : "their partner";
        $instructionText = "The intimate encounter with {$partnerRef} has just ended. {$npc} gives one brief, in-character afterglow remark to {$partnerRef}, then the moment settles back to normal.";
        $actionStr = ($partner !== '') ? "JustTalk {$partner}" : "JustTalk";
        $rmAction = "rolecommand|Instruction@{$npc}@{$instructionText} (must use ACTION {$actionStr})@{$taskId}";
        $GLOBALS["db"]->insert('responselog', array(
            'localts' => time(),
            'sent' => 0,
            'actor' => "rolemaster",
            'text' => '',
            'action' => $rmAction,
            'tag' => 'aiagent_nsfw_pillowtalk'
        ));
        error_log("[AIAGENT_NSFW] Queued NPC pillow-talk turn for {$npc} (partner {$partnerRef})");
        return true;
    }

    // Queue a server-initiated, scene-aware turn for a NON-primary group-scene participant so they chime
    // in during the scene in their own voice. Same responselog rolecommand primitive as pillow talk; the
    // participant's stored scene context makes prerequest inject scene-aware cues. Caller cadence-gates it.
    private static function queueParticipantSceneTurn($npc, $partner) {
        if (!isset($GLOBALS["db"])) { return false; }
        $npc = trim(str_replace(['@', '|'], ' ', (string)$npc));
        $partner = trim(str_replace(['@', '|'], ' ', (string)$partner));
        if ($npc === '') { return false; }
        $escNpc = $GLOBALS["db"]->escape($npc);
        $dupe = $GLOBALS["db"]->fetchOne(
            "SELECT localts FROM responselog WHERE sent=0 AND tag='aiagent_nsfw_groupscene'"
            . " AND action LIKE 'rolecommand|Instruction@{$escNpc}@%' AND localts > " . (time() - 60) . " LIMIT 1"
        );
        if (!empty($dupe)) { return false; }
        $taskId = uniqid();
        $withWhom = ($partner !== '') ? $partner : "the others";
        $instructionText = "You are in the middle of a group scene with {$withWhom}. Give one short, in-character reaction to what is happening to you right now.";
        $rmAction = "rolecommand|Instruction@{$npc}@{$instructionText} (must use ACTION JustTalk)@{$taskId}";
        $GLOBALS["db"]->insert('responselog', array(
            'localts' => time(), 'sent' => 0, 'actor' => "rolemaster", 'text' => '',
            'action' => $rmAction, 'tag' => 'aiagent_nsfw_groupscene'
        ));
        error_log("[AIAGENT_NSFW] Queued group-scene participant turn for {$npc}");
        return true;
    }

    /**
     * Handle chatnf_sl_naked - Actor became naked
     */
    private static function handleNaked() {
        $actor = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);
        $intimacyStatus["is_naked"] = 1;  // 0=clothed, 1=naked (Tyler's design)
        updateIntimacyForActor($actor, $intimacyStatus);
    }

    /**
     * Handle chatnf_sl_climax - Orgasm event
     */
    private static function handleClimax() {
        global $gameRequest;

        $actor = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);

        // Mark this NPC as having orgasmed - used for pillow talk eligibility
        $intimacyStatus["orgasmed"] = true;
        updateIntimacyForActor($actor, $intimacyStatus);
        error_log("[AIAGENT-NSFW] Marked $actor as orgasmed (chatnf_sl_climax)");

        $serviceJustCompleted = !empty($intimacyStatus["service_completed"]);
        // CONSISTENCY (user directive): the refusal/negative climax prompt fires ONLY when the NPC is CURRENTLY
        // refusing - the live scene_phase is "rejected" (a standing refusal, or a forced continuation which keeps
        // scene_phase="rejected"). The old sticky booleans (refusal_expressed / request_scene_stop) linger after a
        // refusal even once she consensually re-engages, which mislabeled a normal climax as non-consent ("negative
        // orgasm prompt after she started"). scene_phase is authoritative: refuse -> refusal prompt; else -> normal.
        $sceneRejected = (($intimacyStatus["scene_phase"] ?? null) === "rejected") && !$serviceJustCompleted;
        $sceneConsent = (!empty($intimacyStatus["accepted_sex"]) && (!function_exists('aiagentNsfwRelTypeSexEligible') || aiagentNsfwRelTypeSexEligible($actor))) // eligibility required to HOLD acceptance (fix 2026-07-01j)
            || !empty($intimacyStatus["npc_is_slave"])
            || (!empty($intimacyStatus["npc_is_prostitute"]) && (!empty($intimacyStatus["payment_confirmed"]) || $serviceJustCompleted))
            || (!empty($intimacyStatus["is_npc_scene"]) && !empty($intimacyStatus["npc_affinity_gate_disabled"]))
            // Consent is model-driven now (no mandatory AcceptSex): a non-prostitute NPC with sex actually underway,
            // who has NOT refused, IS consenting. Without this, a willingly-engaged NPC who never called AcceptSex got
            // force-rejected + scene-exited on climax. Actual refusals still suppress via $sceneRejected above.
            // ELIGIBILITY REQUIRED (fix 2026-07-01g): scene-underway is not consent for a rel-type-ineligible NPC
            || (empty($intimacyStatus["npc_is_prostitute"])
                && function_exists('aiagentNsfwRelTypeSexEligible') && aiagentNsfwRelTypeSexEligible($actor)
                && (!empty($intimacyStatus["sex_started"]) || !empty($intimacyStatus["had_sex_in_scene"]) || (function_exists('getNpcAffinity') && (int)getNpcAffinity($actor) >= (function_exists('aiagentNsfwSceneCallFloorFor') ? (int)aiagentNsfwSceneCallFloorFor($actor) : (int)_getNsfwSetting('NSFW_SCENE_CALL_MIN_AFFINITY', 56)))));
        // SELF-LATCH BREAKER (fix 2026-07-01): same healing as handleOrgasm - chatnf_sl_climax often arrives BEFORE
        // ext_nsfw_orgasm and used to RE-AFFIRM a spurious latch instead of healing it. A consenting NPC with NO
        // sticky refusal (refused_until_scene_end) is not actually rejected; a genuine refusal is untouched.
        if ($sceneRejected && $sceneConsent && empty($intimacyStatus["refused_until_scene_end"])) {
            $intimacyStatus["scene_phase"] = (!empty($intimacyStatus["sex_started"]) || !empty($intimacyStatus["had_sex_in_scene"])) ? "engaged" : "accepted";
            $intimacyStatus["refusal_expressed"] = false;
            $intimacyStatus["forced_scene"] = false;
            updateIntimacyForActor($actor, $intimacyStatus);
            $sceneRejected = false;
            error_log("[AIAGENT-NSFW] Cleared spurious 'rejected' phase for CONSENTING $actor at climax (no sticky refusal) - self-latch broken");
        }
        // ONLY an ACTIVE refusal (the NPC chose RefuseSex -> scene_phase rejected / refusal_expressed) suppresses the
        // climax for a regular NPC. A non-refusing NPC ALWAYS gets a normal climax - we never inject a non-consent
        // reaction just because a consent flag is missing. Prostitutes keep the payment gate (!$sceneConsent).
        if (!empty($intimacyStatus["npc_is_prostitute"]) ? ($sceneRejected || !$sceneConsent) : $sceneRejected) {
            // NO HARD STOP - the model decides. On a refused/non-consented climax, suppress the PLEASURE cue (she
            // reacts through the non-consent boundary, not enjoyment) and keep the scene as a forced continuation;
            // the scene ends when the player / engine ends it, not via a server stop.
            $intimacyStatus["orgasm_generated"] = false;
            $intimacyStatus["orgasm_generated_text"] = "";
            $intimacyStatus["orgasm_generated_text_original"] = "";
            $intimacyStatus["scene_phase"] = "rejected";
            $intimacyStatus["refusal_expressed"] = true;
            $intimacyStatus["forced_scene"] = true;
            updateIntimacyForActor($actor, $intimacyStatus);
            $blockedClimaxContext = (function_exists('getGlobalPrompt') ? getGlobalPrompt('orgasm_refused_scene') : '') ?: "An orgasm/climax was detected, but this scene is refused or has not been accepted. React through the consent/refusal boundary. Do not express pleasure, approval, afterglow, or willingness.";
            $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"] = "<forced_orgasm_instruction>\n{$blockedClimaxContext}\n</forced_orgasm_instruction>";
            error_log("[AIAGENT-NSFW] Climax detected for $actor but positive climax prompt suppressed by consent/refusal gate");
            return;
        }

        if (isset($intimacyStatus["orgasm_generated"]) && $intimacyStatus["orgasm_generated"] && isset($intimacyStatus["orgasm_generated_text"])) {
            // We have used GASP. Let's use it.
            if ($GLOBALS["AIAGENT_NSFW"]["USE_GASP"]) {
                echo "{$actor}|ScriptQueue|" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text_original"])) . "////" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "\r\n";
                error_log("[AIAGENT-NSFW] Climax from orgasm_generated_text_original");
            } else {
                echo "{$actor}|ScriptQueue|" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "////" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "\r\n";
                error_log("[AIAGENT-NSFW] Climax from orgasm_generated_text");
            }

            $intimacyStatus["orgasm_generated"] = false;
            $intimacyStatus["orgasm_generated_text"] = "";
            $intimacyStatus["orgasm_generated_text_original"] = "";

            updateIntimacyForActor($actor, $intimacyStatus);
            $GLOBALS["gameRequest"][0] = "infaction";
            $GLOBALS["gameRequest"][3] = "$actor had an orgasm";
            logEvent($GLOBALS["gameRequest"]);

            terminate();
        } else {
            // NPC will generate response via standard prompt
            // Inject climax_prompt into speech style so it goes directly to NPC (bypasses Narrator path)
            require_once(__DIR__."/nsfw_data.php");
            $extended = NsfwNpcData::get($actor);
            if (!empty($extended["sex_speech_style"])) {
                $speakStyle = NsfwData::getSpeakStyle($extended["sex_speech_style"]);
                if (!empty($speakStyle['climax_prompt'])) {
                    $climaxPrompt = aiagentNsfwResolveSpeakStylePlaceholders($speakStyle['climax_prompt'], $actor, $intimacyStatus);
                    $GLOBALS["HERIKA_SPEECHSTYLE"] = ($GLOBALS["HERIKA_SPEECHSTYLE"] ?? '') . "\n#Climax Behavior\n" . $climaxPrompt;
                    $GLOBALS["AIAGENTNSFW_CLIMAX_PROMPT"] = $climaxPrompt;
                    error_log("[AIAGENT-NSFW] Injected climax_prompt for $actor: " . substr($climaxPrompt, 0, 50) . "...");
                }
            }
            error_log("[AIAGENT-NSFW] Climax from llm_request should happen");
        }
    }

    /**
     * Handle chatnf_sl_moan - Moan event during scene
     */
    private static function handleMoan() {
        // Safety net: if the scene has ended (flag written by preprocessing when chatnf_sl_end
        // arrived), don't generate moan audio. prerequest.php's pillow_talk_pending system
        // handles the NPC's post-scene response for this request instead.
        $sceneEndedRaw = @file_get_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt");
        if ($sceneEndedRaw !== false) {
            $sceneEndedTime  = (int)$sceneEndedRaw;
            $sceneActiveTime = (int)(@file_get_contents(sys_get_temp_dir() . "/nsfw_scene_active.txt") ?: 0);
            if ($sceneEndedTime > $sceneActiveTime && (time() - $sceneEndedTime) < 60) {
                return; // Scene ended — prerequest.php pillow_talk_pending handles the response
            }
        }

        // Gate moans behind the UI toggle AND relationship affinity threshold
        $moansEnabled = !empty($GLOBALS["AIAGENTNSFW_ENABLE_RANDOM_MOANS"]);

        if ($moansEnabled) {
            $randomMoans = $GLOBALS["AIAGENTNSFW_RANDOM_MOANS_LIST"] ?? [" ... oh ... ", " ... ah ... ", " ... mmm ... "];
            $moan = $randomMoans[array_rand($randomMoans)];
            returnLines([$moan]);
        } else {
            error_log("[AIAGENTNSFW] Moan suppressed - toggle off or affinity too low");
            returnLines([""]);
        }

        $actor = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);
        if (!isset($intimacyStatus["orgasm_generated"]) || $intimacyStatus["orgasm_generated"] == false) {
            self::generateClimaxSpeech();
        } else {
            error_log("Orgasm sound already generated");
        }

        terminate();
    }

    /**
     * Handle ext_nsfw_action - Scene action event
     */
    private static function handleAction() {
        global $gameRequest;

        // A scene can end via this action path (e.g. an NPC aborting over a payment dispute:
        // "Scene ended by NPC") instead of the OStim/SexLab end hook. That path never fires
        // chatnf_sl_end, so handleSceneEnd() never runs and the scene state is left armed:
        // intimacy level stays > 0, the scene-active marker stays set, threads stay open. The
        // NPC then keeps re-firing the end and the stuck state force-stops the player's turns.
        // Tear the scene state down here so cleanup ALWAYS happens, however the scene ended.
        $actionData = (string)($gameRequest[3] ?? "");
        if (stripos($actionData, "scene ended") !== false || stripos($actionData, "ended by") !== false) {
            self::teardownSceneState();
        }

        $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
        logEvent($GLOBALS["gameRequest"]);
    }

    /**
     * Tear down lingering scene state for a scene that ended OUT OF BAND (not via chatnf_sl_end):
     * clears the scene markers, ends threads, purges the sex-event backlog, resets cooldowns, and
     * resets every NPC actor's intimacy to "not in a scene". Mirrors the state cleanup in
     * handleSceneEnd() but deliberately does NOT generate pillow talk (the NPC already ended it).
     * This is what stops the NPC re-firing the end and stops the stuck scene from blocking the player.
     */
    private static function teardownSceneState() {
        global $gameRequest;

        $playerName = $GLOBALS['PLAYER_NAME'] ?? 'Player';
        $actor = $GLOBALS["HERIKA_NAME"] ?? null;

        // Recover the actors who were in the scene (same order handleSceneEnd uses).
        $sceneActors = [];
        if ($actor) {
            $currentIntimacy = getIntimacyForActor($actor);
            if (!empty($currentIntimacy["scene_actors"]) && is_array($currentIntimacy["scene_actors"])) {
                $sceneActors = $currentIntimacy["scene_actors"];
            }
        }
        if (empty($sceneActors) && !empty($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"]) && is_array($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"])) {
            $sceneActors = $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"];
        }
        if (empty($sceneActors) && $actor) {
            $sceneActors = [$actor];
        }
        $sceneActors = array_values(array_unique(array_filter(array_map('strval', $sceneActors), function ($n) {
            return trim($n) !== '';
        })));

        error_log("[AIAGENT_NSFW] Out-of-band scene end teardown - actors: " . implode(", ", $sceneActors));

        // End any open scene threads for these actors.
        if (function_exists('aiagentNsfwSceneThreadEndByActors') && !empty($sceneActors)) {
            $endedThreadCount = aiagentNsfwSceneThreadEndByActors($sceneActors);
            if ($endedThreadCount > 0) {
                error_log("[AIAGENT_NSFW] Teardown cleared {$endedThreadCount} active Sharmat scene thread(s)");
            }
        }

        // Purge queued sex-event backlog so nothing re-ignites the scene.
        if (isset($GLOBALS["db"]) && !empty($sceneActors)) {
            $sexTypes = "'chatnf_sl','chatnf_sl_moan','chatnf_sl_naked','chatnf_sl_climax',"
                      . "'ext_nsfw_sexcene','ext_nsfw_orgasm','ext_nsfw_action','ext_nsfw_scene',"
                      . "'chatnf_npc_sl','ext_nsfw_npc_scene','ext_nsfw_npc_invite','ext_nsfw_npc_orgasm',"
                      . "'prechat','rechat','narration'";
            foreach ($sceneActors as $clearActor) {
                if (strcasecmp($clearActor, $playerName) === 0) continue;
                $escapedActor = $GLOBALS["db"]->escape($clearActor);
                $GLOBALS["db"]->delete("eventlog", "type in ($sexTypes) and people like '%|$escapedActor|%'");
                // Flush the un-spoken voice backlog too (see handleSceneEnd) so scene chatter stops immediately.
                $GLOBALS["db"]->delete("eventlog", "type = 'chat' and delivery_state = 'emitted' and people like '%|$escapedActor|%'");
            }
        }

        // Clear scene-active markers and latch the scene-ended marker (releases the rechat throttle).
        @unlink(sys_get_temp_dir() . "/nsfw_scene_active.txt");
        @unlink(sys_get_temp_dir() . "/nsfw_player_scene_active.txt");
        @unlink(sys_get_temp_dir() . "/nsfw_scene_last_hash.txt");
        $sceneEndedTime = time();
        @file_put_contents(sys_get_temp_dir() . "/nsfw_scene_ended.txt", $sceneEndedTime);
        // Player-specific ended marker only if the player was in this torn-down scene (see handleSceneEnd).
        $playerWasInScene = false;
        foreach ($sceneActors as $endCheckActor) {
            if (strcasecmp((string)$endCheckActor, (string)$playerName) === 0) { $playerWasInScene = true; break; }
        }
        if ($playerWasInScene) {
            @file_put_contents(sys_get_temp_dir() . "/nsfw_player_scene_ended.txt", $sceneEndedTime);
        }
        @file_put_contents(sys_get_temp_dir() . "/nsfw_scene_ended_meta.json", json_encode([
            'time' => $sceneEndedTime,
            'gamets' => (float)($gameRequest[2] ?? 0),
        ]));

        // Reset cooldown/response counters.
        if (function_exists('apcu_delete')) {
            foreach ($sceneActors as $sceneActor) {
                apcu_delete("nsfw_responses_{$sceneActor}");
                apcu_delete("nsfw_cooldown_sex_{$sceneActor}");
                apcu_delete("nsfw_cooldown_climax_{$sceneActor}");
            }
        }

        // Reset each NPC actor so nobody is left "in scene". Mirrors ExtCmdStopScene's field set;
        // this is what stops the NPC re-firing the end and stops the stuck scene force-stopping the player.
        foreach ($sceneActors as $sceneActor) {
            if (strcasecmp($sceneActor, $playerName) === 0 || strcasecmp($sceneActor, "Player") === 0) {
                continue;
            }
            $status = getIntimacyForActor($sceneActor);
            $status["level"] = 0;
            $status["scene_phase"] = null;
            $status["accepted_sex"] = false;
            $status["sex_started"] = false;
            $status["is_naked"] = 0;
            $status["is_npc_scene"] = false;
            $status["npc_scene_partner"] = null;
            $status["npc_scene_thread_id"] = null;
            $status["scene_actors"] = null;
            // Clear the scene-context fields the prompt injection reads, so nobody keeps
            // "talking about the scene" after it ends out-of-band (these are the same fields
            // handleSceneEnd clears on the clean path).
            $status["current_scene_desc"] = null;
            $status["current_scene_name"] = null;
            $status["current_scene_tags"] = null;
            $status["current_scene_thread_key"] = null;
            $status["raw_scene_actor_slots"] = null;
            $status["current_primary_partner"] = null;
            $status["is_active_participant"] = false;
            $status["had_sex_in_scene"] = false;
            $status["framework_forced_scene"] = false;
            $status["scene_player_is_victim"] = false;
            $status["scene_actor_is_aggressor"] = false;
            $status["scene_actor_is_victim"] = false;
            $status["is_transaction"] = false;
            $status["transaction_client"] = null;
            $status["negotiation_phase"] = false;
            $status["ready_for_service"] = false;
            $status["payment_pending"] = false;
            $status["payment_pending_amount"] = null;
            $status["payment_pending_service"] = null;
            updateIntimacyForActor($sceneActor, $status);
        }

        error_log("[AIAGENT_NSFW] Out-of-band scene teardown complete - state cleared for " . count($sceneActors) . " actor(s)");
    }

    /**
     * Handle ext_nsfw_scene - Corrected scene event (fixed typo)
     */
    private static function handleSceneEvent() {
        global $gameRequest;

        error_log("[AIAGENT-NSFW] Processing ext_nsfw_scene: {$gameRequest[3]}");

        // Sanitize data
        $sceneData = sanitizeSceneData($gameRequest[3]);

        if ($sceneData['sceneId']) {
            $actorNames = array_column($sceneData['actors'], 'name');
            $actorNames = array_values(array_filter($actorNames, function($actorName) {
                return trim((string)$actorName) !== '' && !nsfwIsNarratorName($actorName);
            }));
            $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";
            $playerInScene = false;
            foreach ($actorNames as $actorName) {
                if (strcasecmp($actorName, $playerName) === 0) {
                    $playerInScene = true;
                    break;
                }
            }
            $primaryPartner = null;
            if ($playerInScene) {
                foreach ($actorNames as $actorName) {
                    if (strcasecmp($actorName, $playerName) !== 0) {
                        $primaryPartner = $actorName;
                        break;
                    }
                }
            }

            // Store actor list globally
            $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"] = $actorNames;
            $GLOBALS["AIAGENTNSFW_RAW_SCENE_ACTOR_SLOTS"] = $actorNames;

	            // Get description using dual lookup
	            $cleanedSceneDesc = getSceneDescription($sceneData['sceneId'], $actorNames);
	            $sceneThreadKey = aiagentNsfwSceneThreadKey($playerInScene ? 'player_scene' : 'npc_scene_ext', $actorNames);
	            aiagentNsfwSceneThreadUpsert($sceneThreadKey, $playerInScene ? 'player_scene' : 'npc_scene', $actorNames, $sceneData['sceneId'], $cleanedSceneDesc, 'ext_nsfw_scene');
	
	            // Update intimacy levels for actors
	            foreach ($actorNames as $actorName) {
                if (strcasecmp($actorName, $playerName) === 0) {
                    continue;
                }
                $intimacyStatus = getIntimacyForActor($actorName);
                $intimacyStatus["level"] = 2; // Active sex
                $intimacyStatus["scene_actors"] = $actorNames;
                $intimacyStatus["raw_scene_actor_slots"] = $actorNames;
	                $intimacyStatus["current_scene_desc"] = $cleanedSceneDesc;
	                $intimacyStatus["current_scene_name"] = $sceneData['sceneId'];
	                $intimacyStatus["current_scene_thread_key"] = $sceneThreadKey;
	                $intimacyStatus["is_npc_scene"] = !$playerInScene;
                if ($playerInScene) {
                    $intimacyStatus["current_primary_partner"] = $primaryPartner;
                } else {
                    $intimacyStatus["npc_scene_partner"] = NsfwNpcScene::resolvePrimaryPartnerForActor($actorName, $actorNames, $sceneData['sceneId']);
                    $intimacyStatus["current_primary_partner"] = $intimacyStatus["npc_scene_partner"];
                    $intimacyStatus["scene_phase"] = "engaged";
                    $intimacyStatus["accepted_sex"] = true;
                    $intimacyStatus["sex_started"] = true;
                    $intimacyStatus["had_sex_in_scene"] = true;
                    $intimacyStatus["npc_affinity_gate_disabled"] = true;
                    $intimacyStatus["last_npc_scene_update_time"] = time();
                }
                updateIntimacyForActor($actorName, $intimacyStatus);
	            }
	
	            // Rewrite data
	            $GLOBALS["gameRequest"][3] = "#INTIMATE SCENE: $cleanedSceneDesc";
	            $GLOBALS["AIAGENTNSFW_FORCE_STOP"] = true;
	            $sceneLogRequest = $GLOBALS["gameRequest"];
	            $sceneLogRequest[3] = aiagentNsfwSceneThreadTag($sceneLogRequest[3], $sceneThreadKey);
	            logEvent($sceneLogRequest);
        }
    }

    /**
     * Handle ext_nsfw_orgasm - Contextual orgasm event
     */
    private static function handleOrgasm() {
        global $gameRequest;

        error_log("[AIAGENT-NSFW] Processing ext_nsfw_orgasm: {$gameRequest[3]}");

        // Data format: "(Context location: X)PlayerName:OrgasmerName/SceneId/Index/Partner"
        // OR for player orgasm: "(Context)PlayerName:PLAYER_ORGASM/SceneId/Index/PlayerName"
        // Need to extract the orgasmer name from after the colon
        $rawData = $gameRequest[3];

        // First split by "/" to get the main parts
        $parts = explode("/", $rawData);
        $firstPart = trim($parts[0] ?? ''); // "(Context)PlayerName:OrgasmerName" or "PLAYER_ORGASM"
        $sceneId = trim($parts[1] ?? '');
        $orgasmerIndex = intval($parts[2] ?? 0);
        $partnerName = trim($parts[3] ?? '');

        // Check for PLAYER_ORGASM prefix (sent when player orgasms)
        $isPlayerOrgasmPrefix = (strpos($rawData, 'PLAYER_ORGASM') !== false);

        // Extract orgasmer name - it's after the colon in the first part
        $orgasmerName = '';
        if (strpos($firstPart, ':') !== false) {
            $colonParts = explode(':', $firstPart);
            $orgasmerName = trim(end($colonParts)); // Get the last part after colon
        } else {
            $orgasmerName = $firstPart;
        }

        error_log("[AIAGENT-NSFW] Parsed orgasm - orgasmer: '$orgasmerName', scene: '$sceneId', partner: '$partnerName', playerOrgasmPrefix: " . ($isPlayerOrgasmPrefix ? 'YES' : 'NO'));

        $actor = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);
        $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";

        // Check if this is the PLAYER having an orgasm
        // Either by PLAYER_ORGASM prefix (new method) or by matching player name (old method)
        $isPlayerOrgasm = $isPlayerOrgasmPrefix || (strcasecmp($orgasmerName, $playerName) === 0 || strcasecmp($orgasmerName, "Player") === 0);
        $sceneActorSlots = is_array($intimacyStatus["raw_scene_actor_slots"] ?? null)
            ? $intimacyStatus["raw_scene_actor_slots"]
            : (is_array($intimacyStatus["scene_actors"] ?? null) ? $intimacyStatus["scene_actors"] : []);
        if (!$isPlayerOrgasm && isset($sceneActorSlots[$orgasmerIndex])) {
            $slotOrgasmer = trim((string)$sceneActorSlots[$orgasmerIndex]);
            if ($slotOrgasmer !== '' && (trim((string)$orgasmerName) === '' || is_numeric($orgasmerName))) {
                $orgasmerName = $slotOrgasmer;
            }
        }
        $eventPartnerName = trim((string)$partnerName);
        if ($isPlayerOrgasm && ($eventPartnerName === '' || strcasecmp($eventPartnerName, $playerName) === 0)) {
            $eventPartnerName = (strcasecmp($actor, $playerName) !== 0) ? $actor : '';
        }
        if ($eventPartnerName === '' && class_exists('NsfwNpcScene')) {
            $resolveFor = $isPlayerOrgasm ? $playerName : ($orgasmerName !== '' ? $orgasmerName : $actor);
            $eventPartnerName = NsfwNpcScene::resolvePrimaryPartnerForActor($resolveFor, $sceneActorSlots, $sceneId);
        }
        if ($eventPartnerName !== '') {
            $partnerName = $eventPartnerName;
        }

        error_log("[AIAGENT-NSFW] Orgasm type: " . ($isPlayerOrgasm ? "PLAYER orgasm (NPC $actor should REACT)" : "NPC $actor orgasm (express their climax)"));

        // A COMPLETED paid prostitute service (player orgasm ended the scene) is a satisfied finish, NOT a
        // refusal - so the scene-stop/payment-consume it triggers must not flag the climax as rejected.
        $serviceJustCompleted = !empty($intimacyStatus["service_completed"]);
        // CONSISTENCY (user directive): only a CURRENT refusal (live scene_phase === "rejected", incl. forced
        // continuation) yields the refusal prompt; stale refusal_expressed / request_scene_stop no longer count, so
        // a consensual climax after an earlier decline gets the normal positive cue. Mirrors handleClimax above.
        $sceneRejected = (($intimacyStatus["scene_phase"] ?? null) === "rejected") && !$serviceJustCompleted;
        $sceneConsent = (!empty($intimacyStatus["accepted_sex"]) && (!function_exists('aiagentNsfwRelTypeSexEligible') || aiagentNsfwRelTypeSexEligible($actor))) // eligibility required to HOLD acceptance (fix 2026-07-01j)
            || !empty($intimacyStatus["npc_is_slave"])
            // Paid prostitute stays consented even after the client's orgasm consumed payment_confirmed.
            || (!empty($intimacyStatus["npc_is_prostitute"]) && (!empty($intimacyStatus["payment_confirmed"]) || $serviceJustCompleted))
            || (!empty($intimacyStatus["is_npc_scene"]) && !empty($intimacyStatus["npc_affinity_gate_disabled"]))
            // Model-driven consent: a non-prostitute NPC with sex underway who hasn't refused is consenting.
            // ELIGIBILITY REQUIRED (fix 2026-07-01g): scene-underway is not consent for a rel-type-ineligible NPC
            || (empty($intimacyStatus["npc_is_prostitute"])
                && function_exists('aiagentNsfwRelTypeSexEligible') && aiagentNsfwRelTypeSexEligible($actor)
                && (!empty($intimacyStatus["sex_started"]) || !empty($intimacyStatus["had_sex_in_scene"]) || (function_exists('getNpcAffinity') && (int)getNpcAffinity($actor) >= (function_exists('aiagentNsfwSceneCallFloorFor') ? (int)aiagentNsfwSceneCallFloorFor($actor) : (int)_getNsfwSetting('NSFW_SCENE_CALL_MIN_AFFINITY', 56)))));
        // SELF-LATCH BREAKER (verified spec 2026-07-01): the suppression block below WRITES scene_phase='rejected' on
        // every suppressed climax, so ONE spurious rejection (a lost scene flag, or an NPC-to-NPC orgasm misrouted to
        // this player handler) used to latch a CONSENTING NPC (e.g. a bonded partner) into the negative "refused" cue
        // for the whole session. A genuinely consenting NPC ($sceneConsent) with NO sticky refusal
        // (refused_until_scene_end - the only thing a real ExtCmdRefuseSex leaves) is NOT actually rejected: clear the
        // stale phase so she gets her positive climax and the latch can never persist. A genuine refusal is untouched.
        if ($sceneRejected && $sceneConsent && empty($intimacyStatus["refused_until_scene_end"])) {
            $intimacyStatus["scene_phase"] = (!empty($intimacyStatus["sex_started"]) || !empty($intimacyStatus["had_sex_in_scene"])) ? "engaged" : "accepted";
            $intimacyStatus["refusal_expressed"] = false;
            $intimacyStatus["forced_scene"] = false;
            updateIntimacyForActor($actor, $intimacyStatus);
            $sceneRejected = false;
            error_log("[AIAGENT-NSFW] Cleared spurious 'rejected' phase for CONSENTING $actor at orgasm (no sticky refusal) - self-latch broken");
        }
        // Regular NPC: pleasure unless they ACTIVELY refused. Prostitute: keep the payment gate ($sceneConsent).
        $orgasmMayUsePleasureCue = !empty($intimacyStatus["npc_is_prostitute"]) ? ($sceneConsent && !$sceneRejected) : !$sceneRejected;

        if (!$orgasmMayUsePleasureCue && empty($intimacyStatus["is_npc_scene"])) {
            $isThisNpcOrgasmingNow = (!$isPlayerOrgasm && strcasecmp($actor, $orgasmerName) === 0);
            if ($isThisNpcOrgasmingNow) {
                $intimacyStatus["orgasmed"] = true;
            }
            // NO HARD STOP - suppress the pleasure cue only; the scene continues as a forced continuation.
            $intimacyStatus["scene_phase"] = "rejected";
            $intimacyStatus["refusal_expressed"] = true;
            $intimacyStatus["forced_scene"] = true;
            $intimacyStatus["orgasm_generated"] = false;
            $intimacyStatus["orgasm_generated_text"] = "";
            $intimacyStatus["orgasm_generated_text_original"] = "";
            updateIntimacyForActor($actor, $intimacyStatus);
            $blockedOrgasmContext = (function_exists('getGlobalPrompt') ? getGlobalPrompt('orgasm_refused_scene') : '') ?: "An orgasm was detected during a scene that is refused or has not been accepted. React through the consent/refusal boundary. Do not express pleasure, approval, afterglow, or willingness.";
            $GLOBALS["AIAGENTNSFW_ORGASM_CUE_OVERRIDE"] = "<forced_orgasm_instruction>\n{$blockedOrgasmContext}\n</forced_orgasm_instruction>";
            error_log("[AIAGENT-NSFW] Orgasm detected for $actor but positive orgasm prompt suppressed by consent/refusal gate");
        }

        // COOLDOWN: If player orgasmed recently, suppress NPC's own orgasm event
        // This prevents the NPC from getting their own climax_prompt right after reacting to player's orgasm
        $playerOrgasmCooldown = 5; // seconds
        if (!$isPlayerOrgasm) {
            // Check if player orgasmed recently
            $lastPlayerOrgasm = $intimacyStatus['last_player_orgasm_time'] ?? 0;
            $timeSincePlayerOrgasm = time() - $lastPlayerOrgasm;
            if ($lastPlayerOrgasm > 0 && $timeSincePlayerOrgasm < $playerOrgasmCooldown) {
                error_log("[AIAGENT-NSFW] SUPPRESSING NPC orgasm for $actor - player orgasmed {$timeSincePlayerOrgasm}s ago (cooldown: {$playerOrgasmCooldown}s)");
                // Still log the event but don't generate a response
                $GLOBALS["gameRequest"][0] = "infoaction";
                $GLOBALS["gameRequest"][3] = "$actor reacts to $playerName's orgasm";
                logEvent($GLOBALS["gameRequest"]);
                terminate();
                return;
            }
        } else {
            // Player is orgasming - record the timestamp
            $intimacyStatus['last_player_orgasm_time'] = time();
            // Client climaxed -> the paid prostitute service is rendered. Consume the payment (next
            // encounter must pay again) AND end the scene - a paid prostitute session completes on the
            // client's orgasm. This restores the prior behavior (player orgasm ends the prostitute
            // scene); a new scene resets service_completed so re-payment still works. Regular NPCs and
            // slaves are unchanged. Her climax line this turn still uses her prostitute speech style.
            if (function_exists('isProstitute') && isProstitute($actor)) {
                $intimacyStatus['payment_confirmed'] = false;
                $intimacyStatus['service_completed'] = true;
                $intimacyStatus['service_end_time'] = time();
                if (function_exists('aiagentNsfwClearPaymentLedger')) {
                    aiagentNsfwClearPaymentLedger($actor, "player orgasm - service rendered");
                }
                $exitLocked = function_exists('aiagentNsfwSceneExitLocked') && aiagentNsfwSceneExitLocked($actor);
                if (!$exitLocked) {
                    $intimacyStatus['request_scene_stop'] = true;
                    if ((!function_exists('aiagentNsfwSceneStopRetryDue') || aiagentNsfwSceneStopRetryDue($intimacyStatus))
                        && function_exists('aiagentNsfwQueuePlayerSceneStop')
                        && aiagentNsfwQueuePlayerSceneStop($actor, aiagentNsfwSceneExitCommand($intimacyStatus))) {
                        if (function_exists('aiagentNsfwMarkSceneStopQueued')) {
                            aiagentNsfwMarkSceneStopQueued($intimacyStatus);
                        } else {
                            $intimacyStatus['stop_command_sent'] = true;
                            $intimacyStatus['last_scene_stop_time'] = time();
                        }
                    }
                    error_log("[AIAGENT-NSFW] Paid prostitute service complete on client orgasm for {$actor} - queued scene stop");
                }
            }
            updateIntimacyForActor($actor, $intimacyStatus);
            error_log("[AIAGENT-NSFW] Recorded player orgasm time for cooldown tracking");
        }

        if ($orgasmMayUsePleasureCue && $isPlayerOrgasm) {
            if (isNpcSlave($actor)) {
                // Inject owner climax reaction prompt for slave
                try {
                    $relationship = RelationshipManager::getPlayerRelationship($actor);
                    $slaveAffinity = $relationship['aff'] ?? 0;
                    // Per-NPC owner_climax override wins; else global affinity-tier default
                    $slaveExtended = NsfwNpcData::get($actor);
                    $ownerClimaxPrompt = $slaveExtended['slave_speak_styles']['owner_climax'] ?? '';
                    if (!empty($ownerClimaxPrompt)) {
                        $ownerClimaxPrompt = str_replace('#PLAYER_NAME#', $playerName, $ownerClimaxPrompt);
                    } else {
                        $ownerClimaxPrompt = NsfwRelationship::getSlaveOwnerClimaxPrompt($slaveAffinity, $playerName);
                    }
                    if (!empty($ownerClimaxPrompt)) {
                        $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . NsfwRelationship::wrapXml('owner_climax_reaction', $ownerClimaxPrompt);
                        error_log("[AIAGENT-NSFW] Injected SLAVE owner climax reaction for $actor (owner: $playerName, affinity: $slaveAffinity)");
                    }
                } catch (Exception $e) {
                    error_log("[AIAGENT-NSFW] Failed to get slave owner climax prompt: " . $e->getMessage());
                }
            } else {
                // Regular NPC - inject partner climax reaction prompt from their speak style if available
                // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
                $extended = NsfwNpcData::get($actor);
                $partnerReactionPrompt = '';

                error_log("[AIAGENT-NSFW] DEBUG: Looking up partner_climax for $actor, sex_speech_style=" . ($extended['sex_speech_style'] ?? 'NOT SET'));

                // Try to get partner_climax_prompt from NPC's speak style
                if (!empty($extended['sex_speech_style'])) {
                    $speakStyle = NsfwData::getSpeakStyle($extended['sex_speech_style']);
                    error_log("[AIAGENT-NSFW] DEBUG: Got speak style, partner_climax_prompt=" . ($speakStyle['partner_climax_prompt'] ?? 'NOT SET'));
                    if (!empty($speakStyle['partner_climax_prompt'])) {
                        $orgasmerForPrompt = $isPlayerOrgasm ? $playerName : $orgasmerName;
                        $partnerReactionPrompt = aiagentNsfwResolveSpeakStylePlaceholders(
                            $speakStyle['partner_climax_prompt'],
                            $actor,
                            $intimacyStatus,
                            ['orgasmer_name' => $orgasmerForPrompt, 'primary_partner' => $orgasmerForPrompt, 'npc_partner' => $partnerName]
                        );
                        error_log("[AIAGENT-NSFW] Using speak style partner_climax_prompt for $actor: " . substr($partnerReactionPrompt, 0, 80));
                    }
                }

                // Only inject if speak style has partner_climax_prompt - no hardcoded fallback
                if (!empty($partnerReactionPrompt)) {
                    $GLOBALS["HERIKA_PERSONALITY"] .= "\n<partner_orgasm>\n{$partnerReactionPrompt}\n</partner_orgasm>";
                    error_log("[AIAGENT-NSFW] Injected partner climax reaction for $actor (partner: $orgasmerName is cumming)");
                    error_log("[AIAGENT-NSFW] Partner climax prompt content: " . substr($partnerReactionPrompt, 0, 100));
                } else {
                    error_log("[AIAGENT-NSFW] No partner_climax_prompt for $actor - skipping injection");
                }
            }
        }

        // Track if THIS NPC is actually orgasming (used later to decide if we inject full speech style)
        $npcIsActuallyOrgasming = false;

        // If NPC is orgasming, build the context message
        // IMPORTANT: Only inject orgasm prompt if THIS NPC ($actor) is the one orgasming
        // Otherwise inject a "react to partner's orgasm" prompt
        if ($orgasmMayUsePleasureCue && !$isPlayerOrgasm) {
            $isThisNpcOrgasming = (strcasecmp($actor, $orgasmerName) === 0);
            $npcIsActuallyOrgasming = $isThisNpcOrgasming;

            if ($isThisNpcOrgasming) {
                // THIS NPC is orgasming - mark them as orgasmed for pillow talk eligibility
                $intimacyStatus["orgasmed"] = true;
                updateIntimacyForActor($actor, $intimacyStatus);
                error_log("[AIAGENT-NSFW] Marked $actor as orgasmed (ext_nsfw_orgasm)");

                // THIS NPC is orgasming - inject their climax prompt
                // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
                $extended = NsfwNpcData::get($orgasmerName);
                $npcOrgasmPrompt = '';

                // Try to get climax_prompt from NPC's speak style
                if (!empty($extended['sex_speech_style'])) {
                    $speakStyle = NsfwData::getSpeakStyle($extended['sex_speech_style']);
                    if (!empty($speakStyle['climax_prompt'])) {
                        $orgasmerStatus = getIntimacyForActor($orgasmerName);
                        $npcOrgasmPrompt = aiagentNsfwResolveSpeakStylePlaceholders(
                            $speakStyle['climax_prompt'],
                            $orgasmerName,
                            $orgasmerStatus,
                            ['orgasmer_name' => $orgasmerName, 'primary_partner' => $partnerName, 'npc_partner' => $partnerName]
                        );
                        error_log("[AIAGENT-NSFW] Using speak style climax_prompt for $orgasmerName: " . substr($npcOrgasmPrompt, 0, 50));
                    }
                }

                // Fall back to global prompt from UI if no speak style climax_prompt
                if (empty($npcOrgasmPrompt)) {
                    $npcOrgasmPrompt = getGlobalPrompt('npc_orgasm_prompt');
                    if (!empty($npcOrgasmPrompt)) {
                        $npcOrgasmPrompt = str_replace('#NPC#', $orgasmerName, $npcOrgasmPrompt);
                        $npcOrgasmPrompt = str_replace('#NPC_NAME#', $orgasmerName, $npcOrgasmPrompt);
                    }
                    // No hardcoded fallback - if nothing in UI, $npcOrgasmPrompt stays empty
                }

                // Only prepend prompt if we have one
                if (!empty($npcOrgasmPrompt)) {
                    $GLOBALS["gameRequest"][3] = $npcOrgasmPrompt . " " . $gameRequest[3];
                    error_log("[AIAGENT-NSFW] NPC orgasm message for $actor: {$GLOBALS["gameRequest"][3]}");
                } else {
                    error_log("[AIAGENT-NSFW] No climax_prompt for $actor - using raw event data only");
                }
            } else {
                // This NPC is NOT the one orgasming - they should REACT to their partner's orgasm
                // Use their speak style's partner_climax_prompt if available
                // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
                $extendedReact = NsfwNpcData::get($actor);
                $partnerReactionPromptNpc = '';

                if (!empty($extendedReact['sex_speech_style'])) {
                    $speakStyleReact = NsfwData::getSpeakStyle($extendedReact['sex_speech_style']);
                    if (!empty($speakStyleReact['partner_climax_prompt'])) {
                        $partnerReactionPromptNpc = aiagentNsfwResolveSpeakStylePlaceholders(
                            $speakStyleReact['partner_climax_prompt'],
                            $actor,
                            $intimacyStatus,
                            ['orgasmer_name' => $orgasmerName, 'npc_partner' => $orgasmerName, 'primary_partner' => $orgasmerName]
                        );
                        error_log("[AIAGENT-NSFW] Using speak style partner_climax_prompt for $actor (reacting to NPC partner)");
                    }
                }

                // Only inject if speak style has partner_climax_prompt - no hardcoded fallback
                if (!empty($partnerReactionPromptNpc)) {
                    $GLOBALS["HERIKA_PERSONALITY"] .= "\n<partner_orgasm>\n{$partnerReactionPromptNpc}\n</partner_orgasm>";
                    error_log("[AIAGENT-NSFW] Injected partner climax reaction for $actor (partner NPC: $orgasmerName is orgasming, NOT $actor)");
                } else {
                    error_log("[AIAGENT-NSFW] No partner_climax_prompt for $actor - skipping partner reaction injection");
                }
            }
        }

        // Get contextual orgasm message if scene ID provided
        $orgasmContext = '';
        if (!empty($sceneId)) {
            $actorNames = !empty($sceneActorSlots) ? $sceneActorSlots : [$orgasmerName, $partnerName];
            $orgasmContext = getOrgasmContext($sceneId, $orgasmerIndex, $actorNames);
        }

        if (isset($intimacyStatus["orgasm_generated"]) && $intimacyStatus["orgasm_generated"] && isset($intimacyStatus["orgasm_generated_text"])) {
            // GASP handling
            if ($GLOBALS["AIAGENT_NSFW"]["USE_GASP"]) {
                echo "{$actor}|ScriptQueue|" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text_original"])) . "////" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "\r\n";
            } else {
                echo "{$actor}|ScriptQueue|" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "////" . trim(unmoodSentence($intimacyStatus["orgasm_generated_text"])) . "\r\n";
            }

            $intimacyStatus["orgasm_generated"] = false;
            $intimacyStatus["orgasm_generated_text"] = "";
            $intimacyStatus["orgasm_generated_text_original"] = "";
            updateIntimacyForActor($actor, $intimacyStatus);

            $contextMsg = !empty($orgasmContext) ? "$actor $orgasmContext" : "$actor had an orgasm";
            $GLOBALS["gameRequest"][0] = "infoaction";
            $GLOBALS["gameRequest"][3] = $contextMsg;
            logEvent($GLOBALS["gameRequest"]);
            terminate();
        } else {
            // Add context to player_request for LLM
            if (!empty($orgasmContext)) {
                $GLOBALS["PROMPTS"]["ext_nsfw_orgasm"]["player_request"] = ["Scene Event: $actor $orgasmContext"];
            }
            error_log("[AIAGENT-NSFW] Contextual orgasm: $orgasmContext");

            // Only inject climax prompt if THIS NPC is actually orgasming
            // Speech style was already injected when scene started - don't re-inject on every orgasm
            if ($orgasmMayUsePleasureCue && $npcIsActuallyOrgasming) {
                // Get climax_prompt directly from NPC's speak style - don't call setSexSpeechStyle
                // which would re-inject the full style (already done in prerequest.php)
                $climaxCue = "";
                $extendedClimax = NsfwNpcData::get($actor);
                if (!empty($extendedClimax['sex_speech_style'])) {
                    $speakStyleClimax = NsfwData::getSpeakStyle($extendedClimax['sex_speech_style']);
                    if (!empty($speakStyleClimax['climax_prompt'])) {
                        $climaxCue = $speakStyleClimax['climax_prompt'];
                    }
                }

                if (!empty($climaxCue)) {
                    $climaxCue = aiagentNsfwResolveSpeakStylePlaceholders(
                        $climaxCue,
                        $actor,
                        $intimacyStatus,
                        ['orgasmer_name' => $actor, 'primary_partner' => $partnerName, 'npc_partner' => $partnerName]
                    );
                    $GLOBALS["PROMPTS"]["ext_nsfw_orgasm"]["cue"] = ["<climax_instruction>\n{$climaxCue}\n</climax_instruction> " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")];
                    error_log("[AIAGENT-NSFW] Applied climax_prompt for $actor: " . substr($climaxCue, 0, 80));
                }
            } else {
                // CRITICAL: Override the default "cue" prompt to tell NPC to REACT to partner's orgasm
                // NOT to express their own climax!
                // Use NPC's speak style partner_climax_prompt if available
                $partnerOrgasmCue = "";
                // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
                $extendedCue = NsfwNpcData::get($actor);

                if (!empty($extendedCue['sex_speech_style'])) {
                    $speakStyleCue = NsfwData::getSpeakStyle($extendedCue['sex_speech_style']);
                    if (!empty($speakStyleCue['partner_climax_prompt'])) {
                        $partnerOrgasmCue = "(" . $speakStyleCue['partner_climax_prompt'] . ")";
                        $partnerOrgasmCue = aiagentNsfwResolveSpeakStylePlaceholders(
                            $partnerOrgasmCue,
                            $actor,
                            $intimacyStatus,
                            ['orgasmer_name' => $orgasmerName, 'npc_partner' => $orgasmerName, 'primary_partner' => $orgasmerName]
                        );
                        error_log("[AIAGENT-NSFW] Using speak style partner_climax_prompt as cue for $actor: " . substr($partnerOrgasmCue, 0, 80));
                    }
                }

                // Only override cue if we have a speak style partner_climax_prompt
                // NO hardcoded fallback - if NPC has no profile, don't override their cue
                if (!empty($partnerOrgasmCue)) {
                    $GLOBALS["PROMPTS"]["ext_nsfw_orgasm"]["cue"] = [$partnerOrgasmCue . " " . ($GLOBALS["TEMPLATE_DIALOG"] ?? "")];
                    error_log("[AIAGENT-NSFW] Overrode cue with speak style partner_climax_prompt - $actor reacting to partner's orgasm");
                } else {
                    error_log("[AIAGENT-NSFW] No partner_climax_prompt for $actor - using existing cue from prerequest.php");
                }
            }
        }
    }

    // ============================================
    // SPEECH STYLE AND PROMPT INJECTION
    // ============================================

    /**
     * Inject sex speech style for actor
     * Handles: speak style content, profanity level, kinks (affinity-gated)
     */
    public static function setSexSpeechStyle($actorName) {
        // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
        $extended = NsfwNpcData::get($actorName);
        $intimacyStatus = getIntimacyForActor($actorName);

        // Check if NPC has a custom speech style configured
        $hasProfile = isset($extended["sex_speech_style"]) && !empty($extended["sex_speech_style"]) && $extended["sex_speech_style"] !== 'auto';
        $styleContent = '';
        $speakStyle = null;

        if ($hasProfile) {
            // NPC HAS PROFILE - use their custom speak style
            $styleName = $extended["sex_speech_style"];
            error_log("[AIAGENTNSFW] NPC $actorName HAS PROFILE - using custom speech style: $styleName");

            // Get full speak style object with all prompts
            $speakStyle = NsfwData::getSpeakStyle($styleName);
            $styleContent = $speakStyle['content'] ?? '';

            // Fallback: check if .txt file exists (legacy support)
            if (empty($styleContent)) {
                $styleFile = __DIR__ . "/speakStyles/" . $styleName . ".txt";
                if (file_exists($styleFile)) {
                    $styleContent = file_get_contents($styleFile);
                }
            }
        } else {
            // NPC has NO PROFILE - use default from UI prompts page (NPC backups)
            $styleContent = getGlobalPrompt('default_speech_style');
            if (!empty($styleContent)) {
                error_log("[AIAGENTNSFW] NPC $actorName has NO PROFILE - using default_speech_style");
            }
        }

        // Inject the speech style (either custom or default)
        if (!empty($styleContent)) {
            $styleContent = aiagentNsfwResolveSpeakStylePlaceholders($styleContent, $actorName, $intimacyStatus);
            $speakStyleTemplate = getGlobalPrompt('speak_style_template');
            if (empty($speakStyleTemplate)) {
                $speakStyleTemplate = "#Sex Expressions\n#SPEAK_STYLE#";
            }
            $speakStyleOutput = aiagentNsfwResolveSpeakStylePlaceholders(
                str_replace('#SPEAK_STYLE#', $styleContent, $speakStyleTemplate),
                $actorName,
                $intimacyStatus
            );
            $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . $speakStyleOutput;

            // Only inject phase prompts if NPC has a profile (speakStyle object exists)
            if ($speakStyle) {
                // Store phase prompts in globals for climax/pillow talk handlers
                if (!empty($speakStyle['climax_prompt'])) {
                    $GLOBALS["AIAGENTNSFW_CLIMAX_PROMPT"] = aiagentNsfwResolveSpeakStylePlaceholders($speakStyle['climax_prompt'], $actorName, $intimacyStatus);
                }
                if (!empty($speakStyle['pillow_talk_prompt'])) {
                    $GLOBALS["AIAGENTNSFW_PILLOW_TALK_PROMPT"] = aiagentNsfwResolveSpeakStylePlaceholders($speakStyle['pillow_talk_prompt'], $actorName, $intimacyStatus);
                }
                if (!empty($speakStyle['masturbation_prompt'])) {
                    $GLOBALS["AIAGENTNSFW_MASTURBATION_PROMPT"] = aiagentNsfwResolveSpeakStylePlaceholders($speakStyle['masturbation_prompt'], $actorName, $intimacyStatus);
                }
            }
        }

        // Inject profanity level if set - READ FROM JSONB SETTINGS
        if (isset($extended["nsfw_profanity_level"]) && !empty($extended["nsfw_profanity_level"])) {
            $profanityLevel = $extended["nsfw_profanity_level"];
            $profanityDesc = '';
            $profanityLabel = '';

            // Normalize to numeric 1-4
            $numericLevel = $profanityLevel;
            if (!is_numeric($profanityLevel)) {
                // Map text to numeric for backwards compatibility
                $textToNumeric = [
                    'soft' => '1',
                    'medium' => '2',
                    'hard' => '3',
                    'naughty' => '4'
                ];
                $numericLevel = $textToNumeric[strtolower($profanityLevel)] ?? '2';
            }

            // Labels for display
            $profanityLabels = [
                '1' => 'Soft',
                '2' => 'Medium',
                '3' => 'Hard',
                '4' => 'Naughty'
            ];
            $profanityLabel = $profanityLabels[$numericLevel] ?? 'Medium';

            // Get profanity description from JSONB settings (config_manager prompts)
            // Load profanity from JSONB - NO hardcoded fallbacks
            // Prompts are configured in config_manager.php aiagent_nsfw_prompts
            require_once __DIR__ . "/nsfw_data.php";
            $prompts = NsfwData::getBlob(NsfwData::KEY_PROMPTS);
            $profanityKey = 'profanity_' . $numericLevel;

            if (isset($prompts[$profanityKey]) && !empty($prompts[$profanityKey])) {
                $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n#Profanity Level: " . $profanityLabel . "\n" . $prompts[$profanityKey];
            }
        }

        // Inject kinks if set - AFFINITY GATED
        $hasNormalKinks = isset($extended["nsfw_kinks"]) && is_array($extended["nsfw_kinks"]) && !empty($extended["nsfw_kinks"]);
        $hasSecretKinks = isset($extended["nsfw_secret_kinks"]) && is_array($extended["nsfw_secret_kinks"]) && !empty($extended["nsfw_secret_kinks"]);

        // Prostitutes are business-only: suppress personal kinks (slaves never reach this function)
        if (($hasNormalKinks || $hasSecretKinks) && !isProstitute($actorName)) {
            // Get affinity with current partner(s)
            // Try GLOBAL first, then fall back to stored intimacy data
            $affinity = 0;
            $sceneActorsForKinks = null;
            $intimacyStatusForKinks = getIntimacyForActor($actorName);
            $npcGateBypassesKinks = !empty($intimacyStatusForKinks["is_npc_scene"]) && !isNpcAffinityGatingEnabled();
            if (isset($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"]) && is_array($GLOBALS["AIAGENTNSFW_SCENE_ACTORS"])) {
                $sceneActorsForKinks = $GLOBALS["AIAGENTNSFW_SCENE_ACTORS"];
            } else if ((($intimacyStatusForKinks["scene_phase"] ?? null) !== "rejected") && empty($intimacyStatusForKinks["refused_until_scene_end"])) { // was $orgasmMayUsePleasureCue: a handleOrgasm() local, ALWAYS undefined here (branch never ran); same non-prostitute meaning, computed locally
                if (!empty($intimacyStatusForKinks["scene_actors"])) {
                    $sceneActorsForKinks = $intimacyStatusForKinks["scene_actors"];
                }
            }

            if ($npcGateBypassesKinks) {
                $affinity = 100;
                error_log("[AIAGENTNSFW] NPC-to-NPC affinity gate disabled - unlocking normal/secret kinks for $actorName");
            } else if (!empty($sceneActorsForKinks)) {
                // Get lowest affinity in group (most restrictive)
                $lowestAffinity = 100;
                foreach ($sceneActorsForKinks as $partner) {
                    if (strtolower($partner) !== strtolower($actorName)) {
                        $partnerAffinity = getNpcAffinity($actorName, $partner);
                        if ($partnerAffinity < $lowestAffinity) {
                            $lowestAffinity = $partnerAffinity;
                        }
                    }
                }
                $affinity = $lowestAffinity;
            } else {
                $affinity = getNpcAffinity($actorName);
            }

            // Thresholds from NPC's extended_data
            $normalKinksThreshold = $extended["nsfw_kinks_unlock_tier"] ?? 56;  // Default: Fond
            $secretKinksThreshold = $extended["nsfw_secret_kinks_unlock_tier"] ?? 76;  // Default: Devoted

            // Load kink templates from database (UI prompts)
            $prompts = NsfwData::getBlob(NsfwData::KEY_PROMPTS);

            // Inject normal kinks if threshold met
            if ($hasNormalKinks && $affinity >= $normalKinksThreshold) {
                $kinksList = implode(", ", $extended["nsfw_kinks"]);
                $normalKinksTemplate = $prompts['normal_kinks_template'] ?? '';
                if (empty($normalKinksTemplate)) {
                    // Code fallback (matches speak_style_template / sex_personality_template) so kinks still
                    // inject when the UI template key is missing from the store - otherwise kinks silently vanish.
                    $normalKinksTemplate = "Your kinks are: #KINKS#. During sex, actively ask #PRIMARY_PARTNER# for at least one of these: pick ONE at random when the moment feels natural and request it in your own voice. Do not recite the whole list.";
                }
                $kinksOutput = str_replace('#KINKS#', $kinksList, $normalKinksTemplate);
                if (function_exists('aiagentNsfwResolveScenePlaceholders')) {
                    $kinksOutput = aiagentNsfwResolveScenePlaceholders($kinksOutput, $actorName, $intimacyStatusForKinks);
                }
                $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . $kinksOutput;
                error_log("[AIAGENTNSFW] Normal kinks unlocked for $actorName (affinity: $affinity >= $normalKinksThreshold)");
            } else if ($hasNormalKinks) {
                error_log("[AIAGENTNSFW] Normal kinks gated for $actorName (affinity: $affinity < $normalKinksThreshold)");
            }

            // Inject secret kinks if threshold met
            if ($hasSecretKinks && $affinity >= $secretKinksThreshold) {
                $secretKinksList = implode(", ", $extended["nsfw_secret_kinks"]);
                $secretKinksTemplate = $prompts['secret_kinks_template'] ?? '';
                if (empty($secretKinksTemplate)) {
                    // Code fallback so secret kinks still inject when the UI template key is missing.
                    $secretKinksTemplate = "Your deepest, darkest desires are: #SECRET_KINKS#. You only reveal these to someone you truly trust. With such a partner, choose ONE at random during sex and dare to ask for it when the moment feels right.";
                }
                $secretKinksOutput = str_replace('#SECRET_KINKS#', $secretKinksList, $secretKinksTemplate);
                if (function_exists('aiagentNsfwResolveScenePlaceholders')) {
                    $secretKinksOutput = aiagentNsfwResolveScenePlaceholders($secretKinksOutput, $actorName, $intimacyStatusForKinks);
                }
                $GLOBALS["HERIKA_SPEECHSTYLE"] .= "\n" . $secretKinksOutput;
                error_log("[AIAGENTNSFW] Secret kinks unlocked for $actorName (affinity: $affinity >= $secretKinksThreshold)");
            } else if ($hasSecretKinks) {
                error_log("[AIAGENTNSFW] Secret kinks gated for $actorName (affinity: $affinity < $secretKinksThreshold)");
            }
        }
    }

    /**
     * Inject sex prompt (personality during scenes)
     * Also injects tier-based relationship context for multi-actor scenes
     */
    public static function setSexPrompt($actorName) {
        // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
        $extended = NsfwNpcData::get($actorName);

        // UI saves to sex_prompt in JSONB - if NPC has profile, use it
        // If no profile, fall back to default_sex_personality from prompts page
        $sexPrompt = $extended["sex_prompt"] ?? null;
        if (empty($sexPrompt)) {
            // NPC has no profile - use default from UI prompts page
            $sexPrompt = getGlobalPrompt('default_sex_personality');
            if (!empty($sexPrompt)) {
                error_log("[AIAGENTNSFW] NPC $actorName has NO PROFILE - using default_sex_personality");
            }
        } else {
            error_log("[AIAGENTNSFW] NPC $actorName HAS PROFILE - using custom sex_prompt");
        }

        // Prostitutes are business-only: skip the regular sex personality, they use their prostitute persona
        if (!empty($sexPrompt) && !isProstitute($actorName)) {
            $sexPersonalityTemplate = getGlobalPrompt('sex_personality_template');
            if (empty($sexPersonalityTemplate)) {
                $sexPersonalityTemplate = "#Personality (sex scenes)\n#SEX_PROMPT#";
            }
            $sexPersonalityOutput = str_replace('#SEX_PROMPT#', $sexPrompt, $sexPersonalityTemplate);
            error_log("[AIAGENTNSFW] Injected sex_prompt for $actorName");
            $GLOBALS["HERIKA_PERSONALITY"] .= "\n" . $sexPersonalityOutput;
        }

        // #5: scene/participant context is injected ONCE by the universal block in prerequest.php
        // (participant-only for accepted/engaged, so the refuse-bearing tier context is NOT re-injected).
        // setSexPrompt only injects the sex personality - this avoids a double <intimate_scene> on the sex-start turn.
    }

    // ============================================
    // CLIMAX SPEECH GENERATION
    // ============================================

    /**
     * Generate climax speech using LLM
     * Creates short orgasm vocalizations
     */
    public static function generateClimaxSpeech() {
        $actor = $GLOBALS["HERIKA_NAME"];
        $intimacyStatus = getIntimacyForActor($actor);

        error_log("[GASP] $actor");

        $scenePhase = $intimacyStatus["scene_phase"] ?? null;
        // A completed paid service (client just orgasmed) is consented + satisfied - the gasp should still fire
        // even though the orgasm consumed payment_confirmed and queued the scene stop.
        $serviceJustCompleted = !empty($intimacyStatus["service_completed"]);
        $hasConsent = !empty($intimacyStatus["accepted_sex"])
            || !empty($intimacyStatus["npc_is_slave"])
            || (!empty($intimacyStatus["npc_is_prostitute"]) && (!empty($intimacyStatus["payment_confirmed"]) || $serviceJustCompleted));
        if (!$serviceJustCompleted && (!$hasConsent || in_array($scenePhase, ["tier_prompt", "rejected", "affection"], true) || !empty($intimacyStatus["request_scene_stop"]))) {
            $intimacyStatus["orgasm_generated"] = false;
            $intimacyStatus["orgasm_generated_text"] = "";
            $intimacyStatus["orgasm_generated_text_original"] = "";
            updateIntimacyForActor($actor, $intimacyStatus);
            error_log("[GASP] Skipping climax generation for $actor - sex not accepted (phase=" . ($scenePhase ?? 'null') . ")");
            return;
        }

        if (!isset($intimacyStatus["orgasm_generated"]) || $intimacyStatus["orgasm_generated"] == false) {
            error_log("Generating gasped orgasm sound");

            $historyData = "";
            $lastPlace = "";
            $lastListener = "";
            $lastDateTime = "";

            // Determine how much context history to use
            $dynamicProfileContextHistory = 50;
            if (isset($GLOBALS["CONTEXT_HISTORY_DYNAMIC_PROFILE"]) && $GLOBALS["CONTEXT_HISTORY_DYNAMIC_PROFILE"] > 0) {
                $dynamicProfileContextHistory = $GLOBALS["CONTEXT_HISTORY_DYNAMIC_PROFILE"];
            } elseif (isset($GLOBALS["CONTEXT_HISTORY"]) && $GLOBALS["CONTEXT_HISTORY"] > 0) {
                $dynamicProfileContextHistory = $GLOBALS["CONTEXT_HISTORY"];
            }

            foreach (json_decode(DataSpeechJournal($GLOBALS["HERIKA_NAME"], $dynamicProfileContextHistory), true) as $element) {
                if ($element["listener"] == "The Narrator") {
                    continue;
                }
                if ($lastListener != $element["listener"]) {
                    $listener = " (talking to {$element["listener"]})";
                    $lastListener = $element["listener"];
                } else {
                    $listener = "";
                }

                if ($lastPlace != $element["location"]) {
                    $place = " (at {$element["location"]})";
                    $lastPlace = $element["location"];
                } else {
                    $place = "";
                }

                if ($lastDateTime != substr($element["sk_date"], 0, 15)) {
                    $date = substr($element["sk_date"], 0, 10);
                    $time = substr($element["sk_date"], 11);
                    $dateTime = "(on date {$date} at {$time})";
                    $lastDateTime = substr($element["sk_date"], 0, 15);
                } else {
                    $dateTime = "";
                }

                $historyData .= trim("{$element["speaker"]}:" . trim($element["speech"]) . " $listener $place $dateTime") . PHP_EOL;
            }

            $partyConf = DataGetCurrentPartyConf();
            $partyConfA = json_decode($partyConf, true);
            Logger::debug($partyConf);

            $updateProfilePrompt = "Write a VERY SHORT sentence (3/4 words) for {$GLOBALS["HERIKA_NAME"]}
            to say when she/he reaches Climax based on Dialogue history, and {$GLOBALS["PLAYER_NAME"]} requests,stuttering. Read  {$GLOBALS["HERIKA_NAME"]} character sheet to figure out her sexual preferences.";

            $head[] = ["role" => "system", "content" => "You are an NSFW writer.  "];
            $currentDynamicProfile = buildDynamicProfileDisplay();

            $prompt[] = ["role" => "user", "content" => "Current character profile you are generating content for:\n" . "Character name:\n" . $GLOBALS["HERIKA_NAME"] . "\nCharacter static biography:\n" . $GLOBALS["HERIKA_PERS"] . "\n" . $currentDynamicProfile];
            $prompt[] = ["role" => "user", "content" => "* Dialogue history:\n" . $historyData];
            $prompt[] = ["role" => "user", "content" => $updateProfilePrompt];
            $contextData = array_merge($head, $prompt);

            if (isset($GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"])) {
                $connector = new LLMConnector();
                $connectionHandler = $connector->getConnector($GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"]);
                error_log("[CORE SYSTEM] Using new profile system {$GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"]["driver"]}/{$GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"]["model"]}");
            } else {
                error_log("No connector defined");
                return;
            }

            // This direct request must inherit the connector's normal output configuration. Do not
            // carry a prompt-specific FORCE_MAX_TOKENS value into it or impose a SHARMAT ceiling.
            $hadForcedMaxTokens = array_key_exists("FORCE_MAX_TOKENS", $GLOBALS);
            $previousForcedMaxTokens = $hadForcedMaxTokens ? $GLOBALS["FORCE_MAX_TOKENS"] : null;
            if ($hadForcedMaxTokens) { unset($GLOBALS["FORCE_MAX_TOKENS"]); }
            try {
                $buffer = $connectionHandler->fast_request($contextData, [], "aiagent_nsfw");
            } finally {
                if ($hadForcedMaxTokens) { $GLOBALS["FORCE_MAX_TOKENS"] = $previousForcedMaxTokens; }
            }

            $original_speech = " ... Ohh .. " . (strtr(trim($buffer), ['"' => '', "{$GLOBALS["HERIKA_NAME"]}:" => ""]));

            $GLOBALS["PATCH_DONT_STORE_SPEECH_ON_DB"] = true;
            unset($GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"]);

            $GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"][] = function ($text) {
                $randomStrings = ["  ", "  "];
                $result = $text;
                $randomIndex = mt_rand(0, count($randomStrings) - 1);
                $words = explode(' ', $text);
                $wordIndex = mt_rand(0, count($words) - 1);
                $randomWord = $words[$wordIndex];
                $insertPosition = strpos($result, $randomWord);
                $result = substr_replace($result, $randomStrings[$randomIndex], $insertPosition, 0);
                error_log("Applying text modifier for XTTS (speed=>0.6) $text => $result " . __FILE__);

                if (function_exists('xtts_fastapi_settings')) {
                    xtts_fastapi_settings(["temperature" => 1, "speed" => 0.6, "enable_text_splitting" => false, "top_p" => 1, "top_k" => 100], true);
                }
                return $result;
            };

            returnLines([$original_speech], false);
            $generatedFile = end($GLOBALS["TRACK"]["FILES_GENERATED"]);

            $intimacyStatus["orgasm_generated"] = true;
            $intimacyStatus["orgasm_generated_text"] = $original_speech;
            $intimacyStatus["orgasm_generated_text_original"] = trim(unmoodSentence($original_speech));

            updateIntimacyForActor($actor, $intimacyStatus);
        } else {
            error_log("Orgasm sound already generated");
        }
    }

    /**
     * GASP audio processing for orgasm sounds
     */
    public static function gasper($original_speech, $moan, $sourceaudio, $sourcevoiceaudio) {
        $moanfile = "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/cough.wav";

        $moanLibrary = [
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxD1.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE1.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE2.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE4.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE5.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxE6.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxF1.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxG1.wav"],
            ["transcription" => "Ahhm Ahm ahms", "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxA1.wav"],
            ["transcription" => true, "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxA2.wav"],
            ["transcription" => true, "file" => "/opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/ClimaxA3.wav"],
        ];
        $selectedIndex = rand(0, sizeof($moanLibrary) - 1);

        if (isset($GLOBALS["AIAGENT_NSFW"]["USE_GASP"]) && $GLOBALS["AIAGENT_NSFW"]["USE_GASP"]) {
            $tempfile = "/tmp/" . uniqid() . ".wav";
            // timeout guard: gasp must never hang the synchronous scene request and hold the MAIN lock
            $command = "timeout 20 /usr/local/bin/gasp $sourceaudio {$moanLibrary[$selectedIndex]["file"]} \"$original_speech\" $tempfile";

            $output = shell_exec($command);
            error_log("[GASP] Command output: " . $output);
            error_log("[GASP] Source {$moanLibrary[$selectedIndex]["file"]}, Out file: " . $tempfile);

            $input = str_replace("..", " ", trim($output));
            $patterns = [
                '/AAaa/' => 'AAah',
                '/aaAA/' => 'aaAH',
                '/AAAA/' => 'AAAA',
                '/AA/' => 'AH',
                '/aaaa/' => 'Aaah',
                '/aa/' => 'Ah',
            ];
            $output = preg_replace(array_keys($patterns), array_values($patterns), $input);
            $output .= "  $original_speech";

            $finalPseudoPhonetic = trim(unmoodSentence($output));
        } else {
            $tempfile = "/tmp/" . uniqid() . ".wav";
            // timeout guard: gasp must never hang the synchronous scene request and hold the MAIN lock
            $command = "timeout 20 /usr/local/bin/gasp /opt/ai/debian-stable/opt/ai/seed-vc/gasper/library/silence.wav {$moanLibrary[$selectedIndex]["file"]} \"$original_speech\" $tempfile";

            $output = shell_exec($command);
            error_log("[GASP] Command output: " . $output);
            error_log("[GASP] Out file: " . $tempfile);

            $input = str_replace("..", " ", trim($output));
            $patterns = [
                '/AAaa/' => 'AAah',
                '/aaAA/' => 'aaAH',
                '/AAAA/' => 'AAAA',
                '/AA/' => 'AH',
                '/aaaa/' => 'Aaah',
                '/aa/' => 'Ah',
            ];
            $output = preg_replace(array_keys($patterns), array_values($patterns), $input);
            $finalPseudoPhonetic = trim(unmoodSentence($output));
        }

        error_log("[GASP] finalPseudoPhonetic: $finalPseudoPhonetic");

        if (!file_exists($tempfile)) {
            error_log("[GASP] Source audio file not found: $tempfile");
        }
        if (!file_exists($sourcevoiceaudio)) {
            error_log("[GASP] Reference audio file not found: $sourcevoiceaudio");
        }

        $sourceAudioPath = realpath($tempfile);
        $referenceAudioPath = realpath($sourcevoiceaudio);

        if (!$sourceAudioPath || !$referenceAudioPath) {
            error_log("[GASP] File path resolution failed.");
        }

        if (!file_exists($sourceAudioPath) || !is_readable($sourceAudioPath)) {
            error_log("[GASP] Source audio file not accessible: " . $sourceAudioPath);
        }
        if (!file_exists($referenceAudioPath) || !is_readable($referenceAudioPath)) {
            error_log("[GASP] Reference audio file not accessible: " . $referenceAudioPath);
        }

        $tempResfile = $GLOBALS["ENGINE_PATH"] . "/soundcache/" . md5($finalPseudoPhonetic) . ".wav";
        $original_speech_cleaned = trim(unmoodSentence($original_speech));
        $tempResfile2 = $GLOBALS["ENGINE_PATH"] . "/soundcache/" . md5($original_speech_cleaned) . ".wav";

        copy($tempfile, $tempResfile);
        error_log("[GASP] $tempResfile saved successfully.");

        return $finalPseudoPhonetic;
    }
}
