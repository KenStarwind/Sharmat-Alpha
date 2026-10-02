<?php

require_once __DIR__ . "/open_mode_policy.php";  // Pure Open Mode state/prompt policy (unit-testable without CHIM bootstrap)
require_once __DIR__ . "/scene_role_policy.php"; // Pure SexLab victim/aggressor role policy
require_once __DIR__ . "/scene_awareness_policy.php"; // LOCAL (branch reldyn-scenes): pure tag-description, hub-scene and scene-voice-speed policy (unit-testable without CHIM)
require_once __DIR__ . "/reldyn_vocal_policy.php"; // LOCAL (branch reldyn-scenes): RelDyn's per-NPC vocal style (silence chance, pace, a line about how the NPC sounds); inert without RelDyn
require_once __DIR__ . "/reldyn_consent_policy.php"; // LOCAL (branch reldyn-consent): RelDyn's consent decision supersedes ours when RelDyn is installed; inert without it
require_once __DIR__ . "/../../lib/chat_helper_functions.php";
require_once __DIR__ . "/nsfw_data.php";  // NsfwNpcData class - MUST be loaded first (functions.php uses it on include)
require_once __DIR__ . "/helpers.php";  // Helper functions (isSexDisposalEnabled, etc.) - separated from function definitions
require_once __DIR__ . "/scene_lookup.php";  // OStim JSON parsing + dual lookup
require_once __DIR__ . "/scene_threads.php";  // Active scene-thread fence for volatile scene context
require_once __DIR__ . "/contact_state.php";  // Active VR contact fence for volatile touch/grab context
require_once __DIR__ . "/nsfw_relationship.php";  // Tier-based relationship prompts
require_once __DIR__ . "/nsfw_npc_scene.php";  // NPC-to-NPC scene handling
require_once __DIR__ . "/nsfw_physics.php";  // VR touch/physics handling
require_once __DIR__ . "/nsfw_ostim_handler.php";  // OStim scene handling
require_once __DIR__ . "/catalog_seed.php";  // Register Sharmat actions in core_action so action_rework's funcret gate doesn't suppress them

/**
 * Apply TTS settings to whichever TTS engine is active.
 * Supports XTTS FastAPI, PocketTTS, and Chatterbox.
 */
function apply_tts_settings($settings, $resetAfter = false) {
    if (function_exists('xtts_fastapi_settings'))
        xtts_fastapi_settings($settings, $resetAfter);
    else if (function_exists('pockettts_settings'))
        pockettts_settings($settings, $resetAfter);
    else if (function_exists('chatterbox_settings'))
        chatterbox_settings($settings, $resetAfter);
}

/**
 * Process OStim/SexLab scene events
 * Wrapper function for backwards compatibility.
 * @see NsfwOstimHandler::processEvent() for implementation
 */
function processInfoSexScene()
{
    NsfwOstimHandler::processEvent();
}

/**
 * Process VR physics events (HIGGS grab, CBPC touch)
 * Wrapper function for backwards compatibility.
 * @see NsfwPhysics::processEvent() for implementation
 */
function processInfoPhysics()
{
    NsfwPhysics::processEvent();
}

/**
 * Process VR Item Events (HIGGS pickup/drop)
 * NO-OP: VR items are now owned by CORE (lib/vr_items.php :: HeldItems/VRItems, main.php:886).
 * The ext copy was migrated to core; loading it here double-declared VRItems and fataled every request.
 */
function processInfoVRItems()
{
    // handled by core - do not load the ext vr_items.php (class collision with core VRItems)
}

function processInfoFertility()
{
    global $gameRequest;

    if ($gameRequest[0] == "fertility_notification") {
        $actor = $GLOBALS["HERIKA_NAME"];
        $subCmd = explode("@", $gameRequest[3]);
        $eventType = $subCmd[1] ?? '';

        // THROTTLE: Safety net to prevent duplicate processing even if FMR sends extras
        // Critical events (birth, death, miscarriage) bypass throttle
        $criticalEvents = ['birth', 'baby_death', 'miscarriage', 'mother_death', 'aborted', 'tragedy'];
        $throttleSeconds = 30;

        if (!in_array($eventType, $criticalEvents)) {
            $cacheKey = "fertility_" . strtolower($actor) . "_" . $eventType;
            $cacheFile = sys_get_temp_dir() . "/" . md5($cacheKey) . ".fmr_cache";

            // For pregnancy progress, also cache the milestone (10% increments)
            $currentValue = $subCmd[2] ?? '';
            if ($eventType == 'pregnant') {
                $milestone = intval(intval($currentValue) / 10) * 10;
                $cacheKey .= "_" . $milestone;
                $cacheFile = sys_get_temp_dir() . "/" . md5($cacheKey) . ".fmr_cache";
            }

            if (file_exists($cacheFile)) {
                $cacheData = @json_decode(file_get_contents($cacheFile), true);
                $lastProcessed = $cacheData['time'] ?? 0;
                $lastValue = $cacheData['value'] ?? '';

                // Skip if same value processed recently
                if ((time() - $lastProcessed) < $throttleSeconds && $lastValue == $currentValue) {
                    $gameRequest[0] = "info";
                    terminate();
                    return;
                }
            }
            // Update cache
            file_put_contents($cacheFile, json_encode(['time' => time(), 'value' => $currentValue]));
        }

        // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
        $extended = NsfwNpcData::get($actor);

        error_log("[CHIM-NSFW FERTILITY] Processing: " . $gameRequest[3]);

        switch ($eventType) {
            case 'pregnant':
                // Format: name@pregnant@progress@fatherName (from FMR_ActorStatus)
                $extended["fertility_is_pregnant"] = true;
                $extended["fertility_progress"] = intval($subCmd[2] ?? 0);
                $fatherRaw = $subCmd[3] ?? '';
                // The Narrator must NEVER be substituted as the player/father - that fabricates lineage.
                // Leave it unknown (empty); consumers degrade gracefully. A genuinely empty father still defaults to the player.
                if (nsfwIsNarratorName($fatherRaw)) {
                    $fatherRaw = '';
                } else if ($fatherRaw === '') {
                    $fatherRaw = $GLOBALS['PLAYER_NAME'] ?? $fatherRaw;
                }
                $extended["fertility_father"] = $fatherRaw;
                break;

            case 'recovery':
                // Format: name@recovery@day (from FMR_ActorStatus rank 101-115)
                $extended["fertility_is_pregnant"] = false;
                $extended["fertility_recovery_day"] = intval($subCmd[2] ?? 0);
                unset($extended["fertility_cycle_phase"]);
                break;

            case 'cycle':
                // Format: name@cycle@menses|ovulation|pms (from FMR_ActorStatus rank 116-119; follicular unreported)
                $extended["fertility_is_pregnant"] = false;
                $extended["fertility_cycle_phase"] = strval($subCmd[2] ?? '');
                unset($extended["fertility_recovery_day"]);
                break;

            case 'conceived':
                // Format: name@conceived@fatherName (FertilityModeConception - the moment itself)
                $conceivedFather = $subCmd[2] ?? '';
                if (nsfwIsNarratorName($conceivedFather)) { $conceivedFather = ''; }
                if ($conceivedFather !== '') { $extended["fertility_father"] = $conceivedFather; }
                $extended["fertility_conceived_ts"] = time();
                unset($extended["fertility_cycle_phase"]);
                break;

            case 'stress':
                // Format: name@stress@cause@severity@healthPct (worn-baby hazard ACTIVE until relief)
                $extended["fertility_stress_cause"] = strval($subCmd[2] ?? 'unknown');
                $extended["fertility_stress_severity"] = intval($subCmd[3] ?? 0);
                $extended["fertility_stress_ts"] = time();
                unset($extended["fertility_relief_ts"]);
                break;

            case 'relief':
                // Format: name@relief@cause (hazard ended)
                $extended["fertility_relief_cause"] = strval($subCmd[2] ?? ($extended["fertility_stress_cause"] ?? 'unknown'));
                $extended["fertility_relief_ts"] = time();
                unset($extended["fertility_stress_cause"]);
                unset($extended["fertility_stress_severity"]);
                unset($extended["fertility_stress_ts"]);
                break;

            case 'cleared':
                // Format: name@cleared (from FMR_ActorStatus rank 0)
                $extended["fertility_is_pregnant"] = false;
                $extended["fertility_progress"] = 0;
                unset($extended["fertility_father"]);
                unset($extended["fertility_recovery_day"]);
                break;

            case 'aborted':
                $extended["fertility_is_pregnant"] = false;
                break;

            case 'birth':
                $extended["fertility_is_pregnant"] = false;
                $extended["fertility_recent_birth"] = $gameRequest[2];
                $extended["fertility_birth_ts"] = time();   // localtime window for the labor/birth prompt
                break;

            case 'baby_damage':
                // Format: name@baby_damage@damage@remainingHealth
                $extended["fertility_baby_health"] = intval($subCmd[3] ?? 100);
                $extended["fertility_baby_damaged"] = true;
                $extended["fertility_damage_ts"] = time();  // recency window for the witness/danger prompt
                break;

            case 'baby_death':
                // Format: name@baby_death@cause
                $extended["fertility_is_pregnant"] = false;
                $extended["fertility_baby_lost"] = true;
                $extended["fertility_baby_death_cause"] = $subCmd[2] ?? 'unknown';
                $extended["fertility_loss_ts"] = time();    // grief window for the loss prompt
                unset($extended["fertility_stress_cause"]);
                unset($extended["fertility_stress_severity"]);
                unset($extended["fertility_stress_ts"]);
                break;

            case 'miscarriage':
                // Format: name@miscarriage@cause
                $extended["fertility_is_pregnant"] = false;
                $extended["fertility_miscarriage"] = true;
                $extended["fertility_miscarriage_cause"] = $subCmd[2] ?? 'unknown';
                $extended["fertility_loss_ts"] = time();    // grief window for the miscarriage prompt
                break;

            case 'baby_status':
                // Format: name@baby_status@ageDays@health@daysRemaining
                $extended["fertility_baby_age_days"] = intval($subCmd[2] ?? 0);
                $extended["fertility_baby_health"] = intval($subCmd[3] ?? 100);
                $extended["fertility_days_remaining"] = intval($subCmd[4] ?? 0);
                break;

            case 'mother_death':
                // Format: name@mother_death@status (pregnant or with_baby)
                $extended["fertility_mother_died"] = true;
                $extended["fertility_death_status"] = $subCmd[2] ?? '';
                break;

            case 'tragedy':
                // Format: name@tragedy@kind@killerName (FMR_BabyTragedy, NG-additive: killer attribution.
                // kind: killed_pregnant / killed_carrying / beaten_miscarriage / beaten_babydeath.
                // The paired FMR_BabyMiscarriage/BabyDeath/MotherDeath events keep maintaining the loss
                // flags on this row; this case only records WHO did it for the witness prompts.)
                $extended["fertility_tragedy_kind"] = strval($subCmd[2] ?? '');
                $tragKiller = strval($subCmd[3] ?? '');
                // Never let the Narrator be named as a killer - that fabricates a person.
                if (nsfwIsNarratorName($tragKiller)) { $tragKiller = ''; }
                $extended["fertility_tragedy_killer"] = ($tragKiller !== '' ? $tragKiller : 'someone');
                $extended["fertility_tragedy_ts"] = time();
                break;
        }

        // Save to nsfw_npc_data table
        NsfwNpcData::save($actor, $extended);

        // Update gamets in core_npc_master (timestamp only)
        $npcManager = new NpcMaster();
        $npcData = $npcManager->getByName($actor);
        if ($npcData) {
            $npcData["gamets_last_updated"] = $gameRequest[2];
            $npcManager->updateByArray($npcData);
        }

        $gameRequest[0] = "info";
        logEvent($gameRequest);
        terminate();
    }
}

function aiagentNsfwIntimacyDataIsFromFuture($intimacyStatus, $currentGamets = null)
{
    if (!is_array($intimacyStatus)) {
        return false;
    }

    $now = (float)($currentGamets ?? ($GLOBALS["gameRequest"][2] ?? 0));
    if ($now <= 0 || !isset($intimacyStatus["gamets"])) {
        return false;
    }

    $stateGamets = (float)$intimacyStatus["gamets"];
    $eventType = (string)($GLOBALS["gameRequest"][0] ?? '');
    if ($eventType !== 'init' && empty($GLOBALS["AIAGENTNSFW_ALLOW_DRAGON_BREAK_CLEANUP"])) {
        return false;
    }

    return $stateGamets > ($now + 1);
}

function getIntimacyForActor($actorName)
{
    // Get from nsfw_npc_data table (NOT core_npc_master.extended_data)
    $extended = NsfwNpcData::get($actorName);

    if (isset($extended["aiagent_nsfw_intimacy_data"]) && isNonEmptyArray($extended["aiagent_nsfw_intimacy_data"])) {
        $intimacyStatus = $extended["aiagent_nsfw_intimacy_data"];
        if (aiagentNsfwIntimacyDataIsFromFuture($intimacyStatus)) {
            unset($extended["aiagent_nsfw_intimacy_data"]);
            NsfwNpcData::save($actorName, $extended);
            error_log("[AIAGENTNSFW] Dragon Break cleanup cleared future intimacy runtime for {$actorName}");
            $intimacyStatus = ["level" => 0, "sex_disposal" => 0];
        }
    } else {
        $intimacyStatus = ["level" => 0, "sex_disposal" => 0];
    }

    // Heal payment_confirmed from the durable ledger. The intimacy blob's payment_confirmed
    // can be clobbered back to false by a stale read-modify-write; the ledger is the source of
    // truth until the player orgasms or the payment window expires. (Anti-clobber, see helpers.)
    $ledger = $extended['aiagent_nsfw_payment_ledger'] ?? null;
    if (is_array($ledger) && !empty($ledger['paid'])
        && function_exists('aiagentNsfwPaymentLedgerActive')
        && aiagentNsfwPaymentLedgerActive($actorName, $ledger)) {
        if (empty($intimacyStatus['payment_confirmed'])) {
            $intimacyStatus['payment_confirmed'] = true;
            if (empty($intimacyStatus['payment_confirmed_amount'])) {
                $intimacyStatus['payment_confirmed_amount'] = (int)($ledger['amount'] ?? 0);
            }
            // getIntimacyForActor runs many times per request; log the heal once per actor per request.
            static $healLogged = [];
            if (empty($healLogged[$actorName])) {
                $healLogged[$actorName] = true;
                error_log("[AIAGENTNSFW] Healed payment_confirmed for {$actorName} from durable ledger (anti-clobber)");
            }
        }
    }

    // AROUSAL DECAY: lazy game-time cooldown, applied once per request on first read. Replaces the old
    // per-conversation-turn -1 (which drained fastest mid-conversation and never while sleeping/waiting).
    // Rate is UI-tunable; sleep/wait sobers the libido like it sobers the liquor.
    static $arousalDecayDone = [];
    if (empty($arousalDecayDone[$actorName]) && function_exists('isSexDisposalEnabled') && isSexDisposalEnabled()
        && $actorName !== ($GLOBALS["PLAYER_NAME"] ?? null)
        && (!function_exists('nsfwIsNarratorName') || !nsfwIsNarratorName($actorName))) {
        $arousalDecayDone[$actorName] = true;
        $adNow = (float)($GLOBALS["gameRequest"][2] ?? 0);
        $adCur = (float)($intimacyStatus['sex_disposal'] ?? 0);
        if ($adNow > 0 && $adCur > 0) {
            $adRate = (float)_getNsfwSetting('AROUSAL_DECAY_PER_GAME_HOUR', 2);
            $adLast = (float)($intimacyStatus['arousal_decay_gamets'] ?? 0);
            $adChanged = false;
            if ($adLast <= 0 || $adLast > ($adNow + 1)) {
                // First sighting of this arousal, or Dragon Break (save reloaded before the stamp): restamp only.
                $intimacyStatus['arousal_decay_gamets'] = $adNow;
                $adChanged = true;
            } elseif ($adRate > 0) {
                $adHours = ($adNow - $adLast) * 0.0000024;
                $adDrop = (int)floor($adHours * $adRate);
                if ($adDrop >= 1) {
                    $intimacyStatus['sex_disposal'] = max(0, $adCur - $adDrop);
                    // Advance the stamp only by the hours consumed so fractional decay accumulates.
                    $intimacyStatus['arousal_decay_gamets'] = $adLast + (($adDrop / $adRate) / 0.0000024);
                    $adChanged = true;
                    error_log("[AIAGENTNSFW] Arousal decay for {$actorName}: -{$adDrop} over " . round($adHours, 2) . " game-h -> {$intimacyStatus['sex_disposal']}");
                }
            }
            if ($adChanged) {
                $extended["aiagent_nsfw_intimacy_data"] = $intimacyStatus;
                NsfwNpcData::save($actorName, $extended);
            }
        }
    }

    return $intimacyStatus;
}

/*
 Make AI aware this NPC has given birth a child recently
*/

function setBirthPrompt($actorName)
{
    $GLOBALS["HERIKA_PERSONALITY"].="\nImportant: {$actorName} had a child recently, (out of context, check 'Baby' item on inventory/equipment, this means $actorName is carrying the baby";
}

// ============================================================================
// [CHIM-CORE CANDIDATE] GIFT ACKNOWLEDGMENT - not NSFW-specific; belongs in core CHIM.
// ----------------------------------------------------------------------------
// When the player hands an NPC an item, core logs it as an 'itemfound' event
// ("<Player> gave 2 Skooma to <NPC>,(value 150 gold)") and that type IS in the
// context feed (main.php sqlfilter) - but it's buried with no cue, so the model
// rarely acknowledges it. This surfaces the most recent gift to THIS NPC as a
// prominent instruction (item + value) so it actually reacts, once per gift.
// Generic enough that Rangroo/Tyler should lift this into core; kept here for now.
// ============================================================================
function aiagentNsfwInjectGiftAcknowledgment($actorName, $mayConsume = true)
{
    if (empty($GLOBALS["db"]) || !is_string($actorName) || trim($actorName) === '') {
        return;
    }
    if (function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($actorName)) {
        return;
    }
    $playerName = (string)($GLOBALS["PLAYER_NAME"] ?? 'The player');
    $aE = $GLOBALS["db"]->escape($actorName);
    $pE = $GLOBALS["db"]->escape($playerName);
    // Most recent player->this-NPC gift, within the last 5 minutes (real time).
    $row = $GLOBALS["db"]->fetchOne(
        "SELECT rowid, data FROM eventlog
         WHERE type = 'itemfound' AND data ILIKE '" . $pE . " gave % to " . $aE . "%'
           AND localts >= (extract(epoch from now())::int - 300)
         ORDER BY rowid DESC LIMIT 1"
    );
    if (empty($row['rowid'])) {
        return;
    }
    $intimacy = function_exists('getIntimacyForActor') ? getIntimacyForActor($actorName) : [];
    $lastAck  = (int)($intimacy['aiagent_nsfw_last_gift_ack_rowid'] ?? 0);
    if ((int)$row['rowid'] <= $lastAck) {
        return; // already acknowledged this gift
    }
    $detail = trim((string)$row['data']);
    if (!preg_match('/gave (.+?) to /i', $detail, $m)) {
        return;
    }
    $giftText = trim($m[1]);
    $valuePart = '';
    if (preg_match('/\(value[^)]*\)/i', $detail, $vm)) {
        $valuePart = ' ' . $vm[0];
    }
    $GLOBALS["HERIKA_PERSONALITY"] .= "\n<gift_received>\n{$playerName} just handed you {$giftText}{$valuePart}. Acknowledge receiving it in character - react to the specific item(s) you were given. You now have it.\n</gift_received>";
    // Only mark the gift as acknowledged (dedup) on a turn where she is actually responding to the player. A SILENT
    // system turn (payment-check, scene tick, etc.) would otherwise "consume" the gift without her ever speaking it,
    // and she'd keep asking for an item she already has. Keep injecting until a real conversational turn surfaces it.
    if ($mayConsume && function_exists('updateIntimacyForActor')) {
        updateIntimacyForActor($actorName, ['aiagent_nsfw_last_gift_ack_rowid' => (int)$row['rowid']]);
    }
    error_log("[AIAGENTNSFW] Gift acknowledgment injected for {$actorName}: {$giftText}{$valuePart}" . ($mayConsume ? " (consumed)" : " (kept - silent turn)"));
}

/**
 * Inject sex speech style for actor
 * Wrapper function for backwards compatibility.
 * @see NsfwOstimHandler::setSexSpeechStyle() for implementation
 */
function setSexSpeechStyle($actorName)
{
    NsfwOstimHandler::setSexSpeechStyle($actorName);
}

/**
 * Inject sex prompt (personality during scenes)
 * Wrapper function for backwards compatibility.
 * @see NsfwOstimHandler::setSexPrompt() for implementation
 */
function setSexPrompt($actorName)
{
    NsfwOstimHandler::setSexPrompt($actorName);
}

function updateIntimacyForActor($actorName, $idata)
{
    if ($actorName == $GLOBALS["PLAYER_NAME"]) {
        return;
    }

    error_log("[AIAGENTNSFW] Updating intimacy for $actorName. " . json_encode($idata));

    // Get from nsfw_npc_data table (NOT core_npc_master.extended_data)
    $extended = NsfwNpcData::get($actorName);

    // Always add/update the gamets timestamp to track when this intimacy data was created
    // This allows us to detect and clear "future" data when loading older saves
    $idata['gamets'] = $GLOBALS["gameRequest"][2] ?? time();

    if (isset($extended["aiagent_nsfw_intimacy_data"]) && isNonEmptyArray($extended["aiagent_nsfw_intimacy_data"])) {
        $extended["aiagent_nsfw_intimacy_data"] = array_merge($extended["aiagent_nsfw_intimacy_data"], $idata);
    } else {
        $extended["aiagent_nsfw_intimacy_data"] = $idata;
    }

    // Save to nsfw_npc_data table
    NsfwNpcData::save($actorName, $extended);
}

/**
 * PLAYER worst-memory decay. NPC<->NPC worst memories are PERMANENT; only the PLAYER's worst memory of an NPC
 * ages out, so one bad event (or a past bug) does not poison the relationship forever. Window is configured on
 * the GLOBAL SETTINGS page in IN-GAME DAYS via PLAYER_WORST_MEMORY_GAME_DAYS (default 7 = one game week; 0 = never
 * decay). It is a core general setting, so it arrives as $GLOBALS['PLAYER_WORST_MEMORY_GAME_DAYS']. Self-stamps
 * worst_gamets + worst_seen the first time it sees a worst, RE-stamps if the worst text changes (a new bad
 * event restarts the clock), and clears the worst record once it is older than the window. Fade-only: the
 * memory text is forgotten; affinity is left to recover through positive interactions (not auto-restored).
 * The worst lives in CHIM core: core_npc_master.extended_data->relationships->Player.
 */
function aiagentNsfwDecayStalePlayerWorstMemory($npcName)
{
    $npcName = trim((string)$npcName);
    if ($npcName === '' || empty($GLOBALS["db"])) {
        return;
    }
    $playerName = $GLOBALS["PLAYER_NAME"] ?? 'Player';
    if (strcasecmp($npcName, $playerName) === 0) {
        return;
    }
    $currentGamets = (float)($GLOBALS["gameRequest"][2] ?? 0);
    if ($currentGamets <= 0) {
        return;
    }

    // IN-GAME DAYS from the Global Settings page (default 7). Stored as a core general setting, so it overlays
    // into $GLOBALS at runtime. 0 (or less) = never decay; the worst memory is kept forever, like NPC<->NPC.
    $decayDays = (isset($GLOBALS['PLAYER_WORST_MEMORY_GAME_DAYS']) && is_numeric($GLOBALS['PLAYER_WORST_MEMORY_GAME_DAYS']))
        ? (float)$GLOBALS['PLAYER_WORST_MEMORY_GAME_DAYS']
        : 7.0;
    if ($decayDays <= 0) {
        return; // 0 (or less) disables decay - worst memory kept forever, like NPC<->NPC
    }
    $decayHours = $decayDays * 24.0;                   // in-game days -> game-hours
    $decayGamets = $decayHours / 0.0000024;            // game-hours -> gamets (codebase constant)
    $cutoff = (int)($currentGamets - $decayGamets);
    $cg = (int)$currentGamets;
    $esc = $GLOBALS["db"]->escape($npcName);

    try {
        // 1) STAMP: PLAYER entry has a worst but no timestamp yet, OR the worst TEXT changed since the last
        //    stamp (a new bad event restarts the clock) -> record current game time + the worst text seen.
        $GLOBALS["db"]->execQuery("
            UPDATE core_npc_master
            SET extended_data = jsonb_set(
                    jsonb_set(extended_data, '{relationships,Player,worst_gamets}', to_jsonb({$cg}::bigint)),
                    '{relationships,Player,worst_seen}', extended_data->'relationships'->'Player'->'worst')
            WHERE npc_name = '{$esc}'
              AND extended_data->'relationships'->'Player' ? 'worst'
              AND ( NOT (extended_data->'relationships'->'Player' ? 'worst_gamets')
                    OR (extended_data->'relationships'->'Player'->'worst_seen')
                       IS DISTINCT FROM (extended_data->'relationships'->'Player'->'worst') )
        ");

        // 2) DECAY: stamped worst older than the window -> forget the memory (clear the worst record).
        $GLOBALS["db"]->execQuery("
            UPDATE core_npc_master
            SET extended_data = jsonb_set(extended_data, '{relationships,Player}',
                    (extended_data->'relationships'->'Player') - 'worst' - 'worst_delta' - 'worst_gamets' - 'worst_seen')
            WHERE npc_name = '{$esc}'
              AND (extended_data->'relationships'->'Player' ? 'worst_gamets')
              AND (extended_data->'relationships'->'Player'->>'worst_gamets')::bigint < {$cutoff}
        ");
    } catch (Exception $e) {
        error_log("[AIAGENTNSFW] Player worst-memory decay failed for {$npcName}: " . $e->getMessage());
    }
}

function aiagentNsfwIssuedCommandTarget($fullcall, $commandName)
{
    $fullcall = (string)$fullcall;
    $commandName = (string)$commandName;
    if ($fullcall === '' || $commandName === '') {
        return '';
    }

    $pattern = '/\b' . preg_quote($commandName, '/') . '@([^\r\n|]*)/i';
    if (preg_match($pattern, $fullcall, $m) !== 1) {
        return '';
    }

    return trim((string)($m[1] ?? ''));
}

function aiagentNsfwSceneConsentPartnerNames($actorName, $intimacyStatus, $extraPartner = '')
{
    $actorName = trim((string)$actorName);
    $playerName = trim((string)($GLOBALS["PLAYER_NAME"] ?? ''));
    $partners = [];

    foreach ([
        $extraPartner,
        $playerName,
        $intimacyStatus["sex_partner"] ?? '',
        $intimacyStatus["last_accept_sex_partner"] ?? '',
        $intimacyStatus["npc_scene_partner"] ?? '',
        $intimacyStatus["current_primary_partner"] ?? ''
    ] as $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            $partners[] = $name;
        }
    }

    if (is_array($intimacyStatus["scene_actors"] ?? null)) {
        foreach ($intimacyStatus["scene_actors"] as $sceneActor) {
            $sceneActor = trim((string)$sceneActor);
            if ($sceneActor !== '') {
                $partners[] = $sceneActor;
            }
        }
    }

    $clean = [];
    foreach ($partners as $name) {
        if (function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($name)) {
            $name = $playerName;
        }
        if ($name === '' || strcasecmp($name, $actorName) === 0) {
            continue;
        }
        $key = strtolower($name);
        if (!isset($clean[$key])) {
            $clean[$key] = $name;
        }
    }

    return array_values($clean);
}

function aiagentNsfwCommandTargetMatchesScene($targetName, $actorName, $intimacyStatus, $extraPartner = '')
{
    $targetName = trim((string)$targetName);
    if ($targetName === '') {
        return false;
    }
    if (function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($targetName)) {
        $targetName = trim((string)($GLOBALS["PLAYER_NAME"] ?? $targetName));
    }

    foreach (aiagentNsfwSceneConsentPartnerNames($actorName, $intimacyStatus, $extraPartner) as $partnerName) {
        if (strcasecmp($targetName, $partnerName) === 0) {
            return true;
        }
    }

    return false;
}

function aiagentNsfwLatestSexConsentCommandForActor($actorName, $intimacyStatus = [], $extraPartner = '')
{
    $actorName = trim((string)$actorName);
    if ($actorName === '' || !isset($GLOBALS["db"]) || !$GLOBALS["db"]) {
        return null;
    }
    if (!is_array($intimacyStatus)) {
        $intimacyStatus = [];
    }

    $now = time();
    $lookbackSeconds = 900;
    $sceneStart = (int)($intimacyStatus["scene_start_time"] ?? 0);
    $since = $now - $lookbackSeconds;
    if ($sceneStart > 0) {
        $since = min($since, $sceneStart - 10);
    }
    $since = max($now - 7200, $since);
    $actorE = $GLOBALS["db"]->escape($actorName);

    $rows = $GLOBALS["db"]->fetchAll(
        "SELECT rowid, localts, gamets, action, fullcall
         FROM public.actions_issued
         WHERE actorname = '{$actorE}'
           AND action IN ('ExtCmdAcceptSex','ExtCmdRefuseSex')
           AND localts >= {$since}
           AND localts <= " . ($now + 60) . "
         ORDER BY localts DESC, rowid DESC
         LIMIT 20"
    );

    if (!is_array($rows)) {
        return null;
    }

    foreach ($rows as $row) {
        $action = (string)($row["action"] ?? '');
        if ($action === 'ExtCmdRefuseSex') {
            $row["target"] = aiagentNsfwIssuedCommandTarget($row["fullcall"] ?? '', $action);
            return $row;
        }
        if ($action !== 'ExtCmdAcceptSex') {
            continue;
        }

        $target = aiagentNsfwIssuedCommandTarget($row["fullcall"] ?? '', $action);
        if (!aiagentNsfwCommandTargetMatchesScene($target, $actorName, $intimacyStatus, $extraPartner)) {
            continue;
        }
        $row["target"] = $target;
        return $row;
    }

    return null;
}

function aiagentNsfwReconcileRecentConsentCommandForActor($actorName, $intimacyStatus = null, $reason = '', $extraPartner = '')
{
    $actorName = trim((string)$actorName);
    if ($actorName === '') {
        return is_array($intimacyStatus) ? $intimacyStatus : [];
    }

    if (!is_array($intimacyStatus)) {
        $intimacyStatus = getIntimacyForActor($actorName);
    }

    $eventType = (string)($GLOBALS["gameRequest"][0] ?? '');
    $hasSceneContext = !empty($intimacyStatus["scene_phase"])
        || !empty($intimacyStatus["scene_actors"])
        || !empty($intimacyStatus["current_scene_desc"])
        || in_array($eventType, [
            'chatnf_sl', 'chatnf_sl_climax', 'chatnf_sl_moan', 'chatnf_sl_naked',
            'ext_nsfw_sexcene', 'ext_nsfw_action', 'ext_nsfw_scene', 'ext_nsfw_orgasm'
        ], true);
    if (!$hasSceneContext || !empty($intimacyStatus["is_npc_scene"]) || (($intimacyStatus["scene_phase"] ?? null) === 'affection')) {
        return $intimacyStatus;
    }

    $latestCommand = aiagentNsfwLatestSexConsentCommandForActor($actorName, $intimacyStatus, $extraPartner);
    if (!is_array($latestCommand) || (string)($latestCommand["action"] ?? '') !== 'ExtCmdAcceptSex') {
        return $intimacyStatus;
    }

    if (function_exists('isProstitute') && isProstitute($actorName) && empty($intimacyStatus["payment_confirmed"])) {
        return $intimacyStatus;
    }

    $phase = (string)($intimacyStatus["scene_phase"] ?? '');
    $targetName = trim((string)($latestCommand["target"] ?? ''));
    if ($targetName === '') {
        $targetName = trim((string)($GLOBALS["PLAYER_NAME"] ?? ''));
    }

    $intimacyStatus["accepted_sex"] = true;
    if ($phase !== 'engaged') {
        $intimacyStatus["scene_phase"] = "accepted";
    }
    $intimacyStatus["refusal_expressed"] = false;
    $intimacyStatus["request_scene_stop"] = false;
    $intimacyStatus["forced_scene"] = false;
    $intimacyStatus["stop_command_sent"] = false;
    $intimacyStatus["last_forced_refusal_scene_key"] = null;
    $intimacyStatus["last_refusal_speech_time"] = null;
    $intimacyStatus["last_refusal_speech_key"] = null;
    $intimacyStatus["sex_partner"] = $targetName;
    $intimacyStatus["last_accept_sex_time"] = (int)($latestCommand["localts"] ?? time());
    $intimacyStatus["last_accept_sex_partner"] = $targetName;
    $intimacyStatus["last_accept_action_rowid"] = (int)($latestCommand["rowid"] ?? 0);
    $intimacyStatus["last_accept_action_reason"] = (string)$reason;

    updateIntimacyForActor($actorName, $intimacyStatus);
    error_log("[AIAGENTNSFW] Reconciled ExtCmdAcceptSex for {$actorName} -> {$targetName}; cleared stale refusal flags ({$reason})");

    return $intimacyStatus;
}

function saveAllDisposals()
{
    error_log("[AIAGENT NSFW] saveAllDisposals is deprecated");
    return;
    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);
    $data       = $GLOBALS["db"]->fetchAll("select * from conf_opts where id like '%_intimacy'");
    $datatoSave = [];
    foreach ($data as $rowactor) {
        $datatoSave[] = $rowactor;
    }

    $GLOBALS["db"]->upsertRowOnConflict(
        "conf_opts",
        [
            "id"    => "aiagent_nsfw_intimacy",
            "value" => json_encode($datatoSave),
        ],
        'id'
    );
    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);

}

function loadAllDisposals()
{
    error_log("[AIAGENT NSFW] loadAllDisposals is deprecated");
    return;

    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);

    $GLOBALS["db"]->execQuery("delete  from conf_opts where id like '%_intimacy'");

    $savedData     = $GLOBALS["db"]->fetchOne("select value from conf_opts where id like 'aiagent_nsfw_intimacy'");
    $savedDataFull = [];

    if ($savedData) {
        $savedDataFull = json_decode($savedData["value"], true);

    }

    if (is_array($savedDataFull)) {
        foreach ($savedDataFull as $actorIntimacyData) {
            $GLOBALS["db"]->upsertRowOnConflict(
                "conf_opts",
                [
                    "id"    => $actorIntimacyData["id"],
                    "value" => $actorIntimacyData["value"],
                ],
                'id'
            );
        }
    }

    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);

}

/*
Calculates intimacy disposal based on moods issued when talking.
*/

function getSexDisposalFromMood($actorName, $currentGamets)
{

    $playerNameE = $GLOBALS["db"]->escape($GLOBALS["PLAYER_NAME"]);
    $actorNameE  = $GLOBALS["db"]->escape($actorName);

    $sdQuery = "
    WITH mood_scores AS (
    SELECT
        speaker,
        listener,
        mood,
        CASE
            WHEN mood = 'playful' THEN 1
            WHEN mood = 'seductive' THEN 1
            WHEN mood = 'sexy' THEN 1
            WHEN mood = 'aroused' THEN 1
            WHEN mood = 'sensual' THEN 1
            WHEN mood = 'flirty' THEN 1
            WHEN mood = 'lovely' THEN 1
            WHEN mood = 'loving' THEN 1
            WHEN mood = 'drunk' THEN 1
            WHEN mood = 'tipsy' THEN 1
            WHEN mood = 'irritated' THEN -2
            WHEN mood = 'grumpy' THEN -1
            ELSE 0
        END AS sex_disposal_speech,gamets
    FROM public.moods_issued
    WHERE mood IS NOT NULL
    and speaker like '$actorNameE'
    and (listener like '$playerNameE' or 1=1)
    and ($currentGamets-gamets)<(7/ 0.0000024)
    order by gamets DESC
    limit 100
)
SELECT
    speaker,
    listener,
    SUM(sex_disposal_speech) AS total_sentiment,
    COUNT(*) AS interactions,
    ROUND(AVG(sex_disposal_speech), 2) AS avg_sentiment,
    MIN(gamets) AS gamets_from,
    MAX(gamets) AS gamets_to
FROM mood_scores
GROUP BY speaker, listener
ORDER BY total_sentiment DESC";

    $statData = $GLOBALS["db"]->fetchOne($sdQuery);
    error_log("[AIGANET NSFW] Mood speech analisys: " . json_encode($statData));
    if (isNonEmptyArray($statData)) {
        return $statData["avg_sentiment"];

    }

    return 0;

}

function getLastIssuedMood($actorName, $currentGamets, $timeFrameLimit = 5)
{

    $playerNameE = $GLOBALS["db"]->escape($GLOBALS["PLAYER_NAME"]);
    $actorNameE  = $GLOBALS["db"]->escape($actorName);

    $sdQuery = "
    select *
    FROM public.moods_issued
    WHERE mood IS NOT NULL
    and speaker like '$actorNameE'
    and gamets <= $currentGamets -- Dragon Break (fix 2026-07-01l): never read moods from an abandoned future timeline after a save reload
    and ($currentGamets-gamets)<(1/ 0.0000024*$timeFrameLimit)
    order by gamets DESC
    limit 1";
    $statData = $GLOBALS["db"]->fetchOne($sdQuery);
    error_log("Last mood: " . json_encode($statData) . "<$sdQuery>");
    if (isNonEmptyArray($statData)) {
        return $statData["mood"];

    }

    return "";

}

function findRowByFirstColumn($filePath, $searchValue)
{
    // Use JSONB storage via NsfwData class
    $lang = $GLOBALS["CORE_LANG"] ?? 'en';
    $description = NsfwData::getSceneDescription($searchValue, $lang);

    if (!empty($description)) {
        error_log("Found JSONB description for $searchValue!");
        return strtolower($description);
    }

    // Auto-insert for future editing
    NsfwData::saveScene($searchValue, '', null, null, null);

    return null; // No match found
}

function findRowByFirstColumnOld($filePath, $searchValue)
{
    if (($fh = fopen($filePath, 'r')) === false) {
        return null;
    }

    $header = fgetcsv($fh, 0, ",", '"', '\\'); // Read and skip header
    while (($row = fgetcsv($fh, 0, ",", '"', '\\')) !== false) {

        if (trim(mb_strtolower($row[0])) === trim(mb_strtolower($searchValue))) {
            error_log("Found description for $searchValue!");
            fclose($fh);
            return $row[1];
        }
    }

    fclose($fh);
    return null; // No match found
}

/**
 * GASP audio processing for orgasm sounds
 * Wrapper function for backwards compatibility.
 * @see NsfwOstimHandler::gasper() for implementation
 */
function gasper($original_speech, $moan, $sourceaudio, $sourcevoiceaudio)
{
    return NsfwOstimHandler::gasper($original_speech, $moan, $sourceaudio, $sourcevoiceaudio);
}

//  Guess if player is naked
function playerIsNaked()
{

    audit_log(__FILE__ . " [AIAGENT NSFW]  " . __LINE__);

    $val = $GLOBALS["db"]->fetchOne("select value from conf_opts where id='player_naked'");
    if ($val && isset($val["value"]) && $val["value"] == 1) {
        return true;
    }

    return false;
}

/**
 * Generate climax speech using LLM
 * Wrapper function for backwards compatibility.
 * @see NsfwOstimHandler::generateClimaxSpeech() for implementation
 */
function generateClimaxSpeech()
{
    NsfwOstimHandler::generateClimaxSpeech();
}

function isProstitute($actorName) {
    $npcManager = new NpcMaster();
    $npcData = $npcManager->getByName($actorName);
    if (!$npcData) {
        $npcData = $npcManager->getByName(ucFirst(strtolower($actorName)));
    }
    if (!$npcData) return false;
    // Get from nsfw_npc_data table (NOT core_npc_master.extended_data)
    $extended = NsfwNpcData::get($actorName);
    // Slave dominates: an enslaved NPC is never routed as a prostitute (the two types are mutually exclusive)
    if (!empty($extended['is_slave'])) return false;
    // The is_prostitute checkbox is the single source of truth (set in the NPC UI or via setNpcProstituteStatus)
    return !empty($extended['is_prostitute']);
}

function setNpcProstituteStatus($actorName, $isProstitute) {
    // Get from nsfw_npc_data table (NOT core_npc_master.extended_data)
    $extended = NsfwNpcData::get($actorName);

    if ($isProstitute) {
        $extended['is_prostitute'] = true;
    } else {
        unset($extended['is_prostitute']);
        unset($extended['profession_prostitute']);
        unset($extended['adult_entertainment_services_autodetected']);
    }

    // Save to nsfw_npc_data table
    NsfwNpcData::save($actorName, $extended);
    return true;
}

function aiagentNsfwIsPlayerReferenceName($targetName) {
    $target = strtolower(trim((string)$targetName));
    if ($target === '' || $target === 'player' || $target === 'the player') {
        return true;
    }

    $playerName = strtolower(trim((string)($GLOBALS['PLAYER_NAME'] ?? '')));
    if ($playerName === '') {
        return false;
    }

    return $target === $playerName || strpos($target, $playerName . ' ') === 0;
}

function getNpcAffinity($npcName, $targetName = null) {
    require_once(__DIR__ . '/nsfw_relationship.php');
    if ($targetName === null || aiagentNsfwIsPlayerReferenceName($targetName)) {
        $relationship = RelationshipManager::getPlayerRelationship($npcName);
    } else {
        $relationship = RelationshipManager::getRelationship($npcName, $targetName);
    }
    return $relationship['aff'] ?? 0;
}

// ORGASM LOCATION (user directive 2026-07-01): map the OStim/SexLab act tag + player sex to a concrete "where did the
// player finish" phrase, phrased in SECOND PERSON (the NPC's own body) because it is injected into the NPC's climax
// reaction. Hardcoded mapping (user's choice). Substring-matched so it works across OStim/SexLab tag spellings.
// Returns '' when the act is unknown/empty so nothing is injected. $isFemalePlayer: the player has no cock to spend.
function aiagentNsfwOrgasmLocationPhrase($actionType, $isFemalePlayer) {
    $a = strtolower(trim((string)$actionType));
    if ($a === '') { return ''; }
    if ($isFemalePlayer) {
        // Female player: she climaxes (no ejaculate) - describe on the NPC's body part in play.
        if (strpos($a, 'finger') !== false || strpos($a, 'handjob') !== false) { return "on your fingers"; }
        if (strpos($a, 'cunn') !== false || strpos($a, 'licking') !== false || strpos($a, 'oral') !== false || strpos($a, 'blow') !== false) { return "on your mouth and face"; }
        if (strpos($a, 'anal') !== false || strpos($a, 'vaginal') !== false) { return "on your cock"; }
        return '';
    }
    // Male player: where his cum lands.
    if (strpos($a, 'anal') !== false) { return "in your ass"; }
    if (strpos($a, 'vaginal') !== false && strpos($a, 'finger') === false) { return "in your pussy"; }
    if (strpos($a, 'boob') !== false || strpos($a, 'tit') !== false || strpos($a, 'paizuri') !== false) { return "on your tits"; }
    if (strpos($a, 'blow') !== false || strpos($a, 'oral') !== false || strpos($a, 'fellatio') !== false
        || strpos($a, 'facial') !== false || strpos($a, 'lickingpenis') !== false || strpos($a, 'deepthroat') !== false) { return "on your face and in your mouth"; }
    if (strpos($a, 'foot') !== false) { return "on your feet"; }
    if (strpos($a, 'finger') !== false || strpos($a, 'handjob') !== false || strpos($a, 'hand') !== false) { return "on your hands and fingers"; }
    return "on your body";
}

function aiagentNsfwSubstanceRegex($settingKey, $defaultTerms) {
    $raw = function_exists('_getNsfwSetting') ? _getNsfwSetting($settingKey, $defaultTerms) : $defaultTerms;
    if (is_array($raw)) {
        $terms = $raw;
    } else {
        $terms = preg_split('/[\r\n,|]+/', (string)$raw);
    }
    $patterns = [];
    foreach ($terms as $term) {
        $term = trim((string)$term);
        if ($term === '') continue;
        $quoted = preg_quote($term, '/');
        $quoted = str_replace('\ ', '[ -]?', $quoted);
        $patterns[] = $quoted;
    }
    if (empty($patterns)) {
        $patterns[] = preg_quote('NO_MATCH_SENTINEL', '/');
    }
    return implode('|', array_values(array_unique($patterns)));
}

function aiagentNsfwAlcoholRegex() {
    return aiagentNsfwSubstanceRegex('ALCOHOL_MATCH_TERMS', 'wine|ale|mead|beer|brandy|spirits|liquor|grog|rum|whiskey|whisky|vodka|gin|absinthe|moonshine|rotgut|firebrand|honningbrew|black-briar|black briar|sujamma|flin|mazte|shein');
}

function aiagentNsfwSkoomaRegex() {
    return aiagentNsfwSubstanceRegex('SKOOMA_MATCH_TERMS', 'skooma|skuma|scuma|schuma|skoomah|skooma bottle|balmora blue|redwater|redwater skooma');
}

function aiagentNsfwSapRegex() {
    return aiagentNsfwSubstanceRegex('SAP_MATCH_TERMS', 'sleeping tree sap|sleeping-tree sap|tree sap|sleeping sap');
}

function aiagentNsfwHardDrugRegex() {
    return aiagentNsfwSkoomaRegex() . '|' . aiagentNsfwSapRegex();
}

function aiagentNsfwDrugDoseActionRegex() {
    $requireConsume = function_exists('_getNsfwSetting') ? _getNsfwSetting('DRUG_REQUIRE_CONSUME_ACTION', true) : true;
    return $requireConsume ? '^Consume$' : '^(Consume|Drink)$';
}

function aiagentNsfwClearKeys(&$data, $keys) {
    $changed = false;
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            unset($data[$key]);
            $changed = true;
        }
    }
    return $changed;
}

function aiagentNsfwIdlePool($settingKey, $defaultList) {
    $raw = function_exists('_getNsfwSetting') ? _getNsfwSetting($settingKey, $defaultList) : $defaultList;
    if (is_array($raw)) {
        $items = $raw;
    } else {
        $items = preg_split('/[\r\n,|]+/', (string)$raw);
    }

    $pool = [];
    foreach ($items as $item) {
        $idle = trim((string)$item);
        if ($idle !== '' && !in_array($idle, $pool, true)) {
            $pool[] = $idle;
        }
    }
    return $pool;
}

function aiagentNsfwReconcileActorRuntimeAfterRollback($actorName, $currentGamets = null) {
    static $done = [];
    if (empty($actorName) || !isset($GLOBALS["db"]) || !$GLOBALS["db"]) return;
    $now = (float)($currentGamets ?? ($GLOBALS["gameRequest"][2] ?? 0));
    if ($now <= 0) return;
    $cacheKey = strtolower($actorName) . '@' . (string)$now;
    if (isset($done[$cacheKey])) return;
    $done[$cacheKey] = true;

    require_once __DIR__ . "/nsfw_data.php";
    $data = NsfwNpcData::get($actorName);
    if (!is_array($data) || empty($data)) return;

    $changed = false;
    $actorE = $GLOBALS["db"]->escape($actorName);
    $doseActionRegex = $GLOBALS["db"]->escape(aiagentNsfwDrugDoseActionRegex());

    if (isset($data["aiagent_nsfw_intimacy_data"]) && is_array($data["aiagent_nsfw_intimacy_data"])
        && aiagentNsfwIntimacyDataIsFromFuture($data["aiagent_nsfw_intimacy_data"], $now)) {
        unset($data["aiagent_nsfw_intimacy_data"]);
        $changed = true;
        error_log("[AIAGENTNSFW] Dragon Break cleanup cleared future intimacy runtime for {$actorName}");
    }

    $hasSkoomaRuntime =
        isset($data["skooma_state"]) ||
        isset($data["skooma_state_gamets"]) ||
        isset($data["skooma_last_dose"]) ||
        isset($data["aiagent_nsfw_prompt_skooma_level"]) ||
        isset($data["aiagent_nsfw_skooma_av"]) ||
        isset($data["aiagent_nsfw_skooma_speed"]) ||
        isset($data["aiagent_nsfw_skooma_dance_ts"]) ||
        isset($data["aiagent_nsfw_skooma_crazed_ts"]);

    if ($hasSkoomaRuntime) {
        $skoomaRegex = $GLOBALS["db"]->escape(aiagentNsfwSkoomaRegex());
        $row = $GLOBALS["db"]->fetchOne(
            "SELECT max(gamets) AS g FROM public.actions_issued
             WHERE actorname = '{$actorE}'
               AND gamets <= {$now}
               AND action ~* '{$doseActionRegex}'
               AND fullcall ~* '{$skoomaRegex}'"
        );
        $latestDose = isset($row['g']) && $row['g'] !== null ? (float)$row['g'] : 0.0;
        $storedLastDose = isset($data["skooma_last_dose"]) ? (float)$data["skooma_last_dose"] : 0.0;
        $state = $data["skooma_state"] ?? 'sober';
        $unsupported =
            $storedLastDose > $now ||
            ($state !== 'sober' && $latestDose <= 0) ||
            ($storedLastDose > 0 && $latestDose > 0 && $storedLastDose > ($latestDose + 1));

        if ($unsupported) {
            if (!class_exists('Npcmaster')) { require_once __DIR__ . "/../../lib/core/npc_master.class.php"; }
            $refidSk = (new Npcmaster())->getByName($actorName)["refid"] ?? null;
            if (!empty($refidSk)) {
                aiagentNsfwQueueSkoomaReset($actorName, $refidSk);
            }
            $changed = aiagentNsfwClearKeys($data, [
                "skooma_state",
                "skooma_state_gamets",
                "skooma_last_dose",
                "aiagent_nsfw_prompt_skooma_level",
                "aiagent_nsfw_skooma_av",
                "aiagent_nsfw_skooma_speed",
                "aiagent_nsfw_skooma_dance_ts",
                "aiagent_nsfw_skooma_crazed_ts",
            ]) || $changed;
            error_log("[AIAGENTNSFW] Dragon Break cleanup cleared unsupported skooma runtime for {$actorName}");
        }
    }

    if (isset($data["aiagent_nsfw_drug_down"]) || isset($data["aiagent_nsfw_sap_paralysis_applied"]) || isset($data["aiagent_nsfw_prompt_sap_level"])) {
        $windowHours = (float)(function_exists('_getNsfwSetting') ? _getNsfwSetting('DRUG_WINDOW_HOURS', 6) : 6);
        if ($windowHours <= 0) $windowHours = 6;
        $minGamets = $now - ($windowHours / 0.0000024);
        $sapRegex = $GLOBALS["db"]->escape(aiagentNsfwSapRegex());
        $sapRow = $GLOBALS["db"]->fetchOne(
            "SELECT count(*) AS c FROM public.actions_issued
             WHERE actorname = '{$actorE}'
               AND gamets > {$minGamets} AND gamets <= {$now}
               AND action ~* '{$doseActionRegex}'
               AND fullcall ~* '{$sapRegex}'"
        );
        if ((int)($sapRow['c'] ?? 0) <= 0) {
            $changed = aiagentNsfwClearKeys($data, [
                "aiagent_nsfw_prompt_sap_level",
            ]) || $changed;
            // Do not clear aiagent_nsfw_drug_down / aiagent_nsfw_sap_paralysis_applied here. If we already
            // sent the paralysis drop, context.php must see the marker once while sober so it can queue rouse.
            error_log("[AIAGENTNSFW] Dragon Break cleanup cleared unsupported sap prompt runtime for {$actorName}");
        }
    }

    $drunkRuntimeKeys = [
        "aiagent_nsfw_prompt_drunk_stage",
        "aiagent_nsfw_last_time_drunk",
        "aiagent_nsfw_last_phys_ts",
        "aiagent_nsfw_drunk_av",
    ];
    $hasDrunkRuntime = false;
    foreach ($drunkRuntimeKeys as $key) {
        if (array_key_exists($key, $data)) { $hasDrunkRuntime = true; break; }
    }
    if ($hasDrunkRuntime && getDrunkStageForActor($actorName) <= 0) {
        $changed = aiagentNsfwClearKeys($data, $drunkRuntimeKeys) || $changed;
        // Do not clear aiagent_nsfw_unconscious here. If paralysis was applied before a save/load rollback,
        // context.php or postrequest.php must still see the marker so it can queue a wake-up.
        error_log("[AIAGENTNSFW] Dragon Break cleanup cleared unsupported alcohol runtime for {$actorName}");
    }

    if ($changed) {
        NsfwNpcData::save($actorName, $data);
    }
}

function aiagentNsfwBuildSkoomaAddictionBargainContext($actorName, $partnerName = null, $sceneDesc = '') {
    $partnerName = $partnerName ?: ($GLOBALS["PLAYER_NAME"] ?? "your partner");
    $prompt = getGlobalPrompt('skooma_addiction_bargain');
    if (trim((string)$prompt) === '') {
        $prompt = "You are in skooma Level 3 withdrawal and desperately need another bottle. #PLAYER_NAME# is using that need as leverage for intimacy. This is not normal romance or normal arousal: it is an addiction bargain. You may bargain, plead, resent the leverage, accept because the craving is stronger than your pride, or refuse if your boundary wins. If you accept this bargain, use AcceptSex. If you refuse it, use RefuseSex. Do not treat acceptance as affection or love; treat it as a desperate choice made under withdrawal.";
    }
    $prompt = str_replace(
        ['#NPC_NAME#', '#PLAYER_NAME#', '#SCENE_DESC#'],
        [$actorName, $partnerName, $sceneDesc],
        $prompt
    );
    return "<skooma_addiction_bargain>\n{$prompt}\n</skooma_addiction_bargain>";
}

function aiagentNsfwSendIdle($actorName, $refid, $idleNameOrFormId, $localts = null) {
    $idle = trim((string)$idleNameOrFormId);
    $refid = trim((string)$refid);
    if ($actorName === '' || $refid === '' || $idle === '') {
        return false;
    }

    // IDLE NAME -> FORMID RESOLVER (single source of truth - keep the readable editor-id NAMES in the
    // pools/UI; this resolves them to FormIDs so they play via PlayIdle). The current CHIM DLL's
    // CommandAnimation path uses SendAnimationEvent, which CANNOT play Idle *records* like these - only
    // PlayIdle(FormID) works (FormIDs sourced from FormIDAnimation.txt). Add any new idle here by name.
    static $idleFormIdMap = [
        // --- Skooma ---
        'idleritualstart'           => '0x000F1AA0', // post-consume ritual
        'idleco2ceremony1welcome'   => '0x000F4331',
        'idleco2cermony1welcome'    => '0x000F4331', // tolerate vanilla/legacy misspelling
        'idlelaugh'                 => '0x00075C5F',
        'idleciceroagitated'        => '0x00103655',
        'idlecivilwarcheer'         => '0x000F7C8C',
        'idlegetattention'          => '0x0006FF15',
        'idleapplaud4'              => '0x000D8732',
        'idleapplaud5'              => '0x000D8733',
        'idlecicerodance1'          => '0x000F7C8A',
        'idlecicerodance2'          => '0x000F7C8B',
        'idlecicerodance3'          => '0x00103653',
        'idlewipebrow'              => '0x000977EC',
        'idlesleepnod'              => '0x000977F1',
        'idlewarmhands'             => '0x0002E52A',
        // --- Slave ---
        'idlemq201holdingdrinktray' => '0x00103657',
        'idleloosesweepingstart'    => '0x000640FE',
        'idlesnaptoattention'       => '0x000B240B',
        'idlepray'                  => '0x0006F300',
        'idlestudy'                 => '0x000977ED',
        'idlesilentbow'             => '0x000D8734',
        'idlesurrender'             => '0x00105D47',
        'idlesurennder'             => '0x00105D47', // tolerate legacy misspelling
        'idleexamine'               => '0x00075C3D',
        'idlebracedpain'            => '0x000D8735',
        'idlebowheadatgrave_01'     => '0x000977EF',
        'idlebowheadatgrave_02'     => '0x000977F0',
        'idlegrave_01'              => '0x000977EF', // legacy alias -> closest vanilla record
        'idlegrave_02'              => '0x000977F0',
        // NOTE: 'IdleWorship' has no vanilla record in FormIDAnimation.txt - left unmapped (won't play
        // until a real FormID/source is confirmed). Add it here once known.
    ];
    $mapKey = strtolower($idle);
    if (isset($idleFormIdMap[$mapKey])) {
        $idle = $idleFormIdMap[$mapKey];
    }

    // ScriptProxy PlayIdle resolves akIdle through Game.GetFormEx(), so it only works with numeric FormIDs.
    // Editor-id animation events must go through CHIM's CommandAnimation path.
    if (preg_match('/^(?:0x)?[0-9a-fA-F]{6,8}$/', $idle)) {
        if (!class_exists('SkyrimCommandBuilder')) { require_once __DIR__ . "/../../lib/scriptproxy_papyrus.php"; }
        $cmd = new SkyrimCommandBuilder();
        $idleForm = (stripos($idle, '0x') === 0) ? $idle : "0x{$idle}";
        $refForm = (stripos($refid, '0x') === 0) ? $refid : "0x{$refid}";
        $cmd->send($cmd->Actor->PlayIdle($refForm, $idleForm), $localts);
        error_log("[AIAGENTNSFW] Queued PlayIdle FormID {$idleForm} for {$actorName}");
        return true;
    }

    $GLOBALS["db"]->insert(
        'responselog',
        [
            'localts' => $localts ?? time(),
            'sent'    => 0,
            'actor'   => $actorName,
            'text'    => "",
            'action'  => 'command|CommandAnimation@' . $idle,
            'tag'     => '',
        ]
    );
    error_log("[AIAGENTNSFW] Queued CommandAnimation {$idle} for {$actorName}");
    return true;
}

function aiagentNsfwQueueDrunkPhysics($actorName, $reaction, $stage, $force, $requireMovement = true, $localts = null) {
    $actorName = trim((string)$actorName);
    $reaction = strtolower(trim((string)$reaction));
    $stage = max(0, (int)$stage);
    $force = max(0.1, (float)$force);
    $require = $requireMovement ? 1 : 0;

    if ($actorName === '' || !in_array($reaction, ['stumble', 'fall'], true)) {
        return false;
    }

    $GLOBALS["db"]->insert(
        'responselog',
        [
            'localts' => $localts ?? time(),
            'sent'    => 0,
            'actor'   => $actorName,
            'text'    => "",
            'action'  => 'command|ExtCmdDrunkPhysics@' . $reaction . '@' . $stage . '@' . $force . '@' . $require,
            'tag'     => '',
        ]
    );
    error_log("[AIAGENTNSFW] Queued drunk {$reaction} physics for {$actorName} (stage {$stage}, movement_required {$require})");
    return true;
}

function aiagentNsfwQueueStage9DrunkPhysics($actorName, $force, $movingFallChance = 60, $standingFallChance = 20, $stumbleChance = 50, $localts = null) {
    $actorName = trim((string)$actorName);
    $force = max(0.1, (float)$force);
    $movingFallChance = max(0, min(100, (int)$movingFallChance));
    $standingFallChance = max(0, min(100, (int)$standingFallChance));
    $stumbleChance = max(0, min(100, (int)$stumbleChance));

    if ($actorName === '') {
        return false;
    }

    $GLOBALS["db"]->insert(
        'responselog',
        [
            'localts' => $localts ?? time(),
            'sent'    => 0,
            'actor'   => $actorName,
            'text'    => "",
            'action'  => 'command|ExtCmdDrunkPhysics@stage9_roll@9@' . $force . '@1@' . $movingFallChance . '@' . $standingFallChance . '@' . $stumbleChance,
            'tag'     => '',
        ]
    );
    error_log("[AIAGENTNSFW] Queued drunk stage9 physics roll for {$actorName} (moving_fall {$movingFallChance}, standing_fall {$standingFallChance}, stumble {$stumbleChance})");
    return true;
}

// Shared scene-EXIT lock (single source of truth). A deeply drunk (>= DRUNK_SCENE_LOCK_STAGE, default 7) or
// Sleeping-Tree-Sap-dosed NPC can refuse/protest but CANNOT end/exit a scene. Used by the model-tool gate
// (functions.php), the refusal router (RefuseSex FUNCSERV), the server refusal paths (prerequest.php), and the
// hard-stop helper below - so the exit cannot be forced through any path. Each axis is independently toggleable.
function aiagentNsfwSceneExitLocked($actorName) {
    $actorName = trim((string)$actorName);
    if ($actorName === '') return false;
    $stage      = (int)(function_exists('_getNsfwSetting') ? _getNsfwSetting('DRUNK_SCENE_LOCK_STAGE', 7) : 7);
    $drunkLocks = function_exists('_getNsfwSetting') ? (bool)_getNsfwSetting('DRUNK_LOCKS_SCENE_EXIT', true) : true;
    $sapLocks   = function_exists('_getNsfwSetting') ? (bool)_getNsfwSetting('SAP_LOCKS_SCENE_EXIT', true) : true;
    if ($drunkLocks && function_exists('getDrunkStageForActor') && getDrunkStageForActor($actorName) >= $stage) return true;
    if ($sapLocks && function_exists('getDrugStageForActor') && getDrugStageForActor($actorName, 'sleeping_tree_sap') >= 1) return true;
    return false;
}

function aiagentNsfwSceneStopRetryDue($intimacyStatus) {
    if (!is_array($intimacyStatus)) return true;
    if (empty($intimacyStatus["stop_command_sent"])) return true;

    $cooldown = function_exists('_getNsfwSetting') ? (int)_getNsfwSetting('SCENE_STOP_RETRY_SECONDS', 3) : 3;
    if ($cooldown < 1) $cooldown = 1;

    $lastStop = (int)($intimacyStatus["last_scene_stop_time"] ?? 0);
    return $lastStop <= 0 || (time() - $lastStop) >= $cooldown;
}

function aiagentNsfwMarkSceneStopQueued(&$intimacyStatus) {
    if (!is_array($intimacyStatus)) $intimacyStatus = [];
    $intimacyStatus["stop_command_sent"] = true;
    $intimacyStatus["last_scene_stop_time"] = time();
    $intimacyStatus["scene_stop_retry_count"] = (int)($intimacyStatus["scene_stop_retry_count"] ?? 0) + 1;
}

function aiagentNsfwRefusalSpeechDue($intimacyStatus, $refusalKey = '') {
    if (!is_array($intimacyStatus)) return true;

    $cooldown = function_exists('_getNsfwSetting') ? (int)_getNsfwSetting('REFUSAL_SPEECH_COOLDOWN_SECONDS', 15) : 15;
    if ($cooldown < 1) $cooldown = 1;

    $lastSpeech = (int)($intimacyStatus["last_refusal_speech_time"] ?? 0);
    return $lastSpeech <= 0 || (time() - $lastSpeech) >= $cooldown;
}

function aiagentNsfwMarkRefusalSpeech(&$intimacyStatus, $refusalKey = '') {
    if (!is_array($intimacyStatus)) $intimacyStatus = [];
    $intimacyStatus["last_refusal_speech_time"] = time();
    $intimacyStatus["last_refusal_speech_key"] = (string)$refusalKey;
}

// Queue the MECHANICAL scene stop. Only ExtCmdStopScene is a real stop now (RefuseSex is speech/state only after
// the Papyrus split), so this refuses any other command. Self-suppresses when the actor is exit-locked: she
// refuses, the scene is NOT force-stopped. Returns true only if a stop was actually queued.
// Refusal/rejection exits: in a 3+ actor scene the refuser LEAVES and the scene continues without them
// (ExtCmdLeaveScene = stop + restart minus the leaver game-side; OStim has no remove-actor API).
// Couple scenes still get the full ExtCmdStopScene. Non-consent completions keep the full stop.
function aiagentNsfwSceneExitCommand($intimacyStatus) {
    $actors = is_array($intimacyStatus) ? ($intimacyStatus['scene_actors'] ?? []) : [];
    return (is_array($actors) && count($actors) > 2) ? 'ExtCmdLeaveScene' : 'ExtCmdStopScene';
}

function aiagentNsfwQueuePlayerSceneStop($actorName, $command = 'ExtCmdStopScene', $localts = null) {
    $actorName = trim((string)$actorName);
    $command = trim((string)$command);
    if ($actorName === '' || $command !== 'ExtCmdStopScene') {
        return false;
    }
    if (aiagentNsfwSceneExitLocked($actorName)) {
        error_log("[AIAGENTNSFW] Scene-stop SUPPRESSED for {$actorName} (drunk/sap exit-lock) - refusal stands, scene continues");
        return false;
    }

    $GLOBALS["db"]->insert(
        'responselog',
        [
            'localts' => $localts ?? time(),
            'sent'    => 0,
            'actor'   => $actorName,
            'text'    => $command . '@declined',
            'action'  => 'command',
            'tag'     => '',
        ]
    );
    error_log("[AIAGENTNSFW] Queued {$command} hard stop for {$actorName}");
    return true;
}

// SHARMAT AROUSAL AUTHORITY: publish SHARMAT's server-authored arousal to the game so OSL can
// mirror it and OStim can use it for its excitement floor/tempo. ONE-WAY by design: SHARMAT is
// the author; neither consumer is ever read back and neither can overwrite the canonical state.
// Rides the responselog command lane (same as the refusal hard-stop): ExtCmdSyncArousal@<0-100>.
// Player-input-only delivery prevents the old command/rechat feedback loop; meaningful-value
// debounce prevents redundant rows. Active scenes are deliberately NOT excluded: SHARMAT remains
// authoritative during them, and the client applies updates without resetting live progression.
function aiagentNsfwQueueAuthoritativeArousalSyncForTurn() {
    if (!_getNsfwSetting('NSFW_OSLA_SYNC_ENABLED', true)) { return; }
    // HOTFIX (DemodiX 2026-07-18, runaway rechat/token burn): sync ONLY on real player-input turns.
    // The v1 pushed after EVERY turn incl. rechat - each responselog row reads as NPC activity to the
    // game, keeping the conversation "alive", which triggers another rechat, whose mood shifts arousal,
    // which queues another row: a self-feeding loop. Player-input-only turns cannot loop (the player
    // is the only trigger), and the value catches up on the next real exchange anyway.
    $oslaSyncEvent = (string)($GLOBALS["gameRequest"][0] ?? '');
    if (!in_array($oslaSyncEvent, ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'], true)) { return; }
    $actorName = trim((string)($GLOBALS["HERIKA_NAME"] ?? ''));
    if ($actorName === '' || nsfwIsNarratorName($actorName)) { return; }
    if (function_exists('aiagentNsfwIsProtectedNpc') && aiagentNsfwIsProtectedNpc($actorName)) { return; }
    $st = getIntimacyForActor($actorName);
    if (!is_array($st)) { return; }
    $oslaValue = (int)max(0, min(100, round(((float)($st['sex_disposal'] ?? 0)) * 3.3)));
    $prev = aiagentNsfwRuntimeStateGet('osla_sync', $actorName);
    $prevValue = is_array($prev) ? (int)($prev['value'] ?? -1) : -1;
    $prevTs = is_array($prev) ? (int)($prev['ts'] ?? 0) : 0;
    // Meaningful changes only. Because delivery is player-input-only, a command row cannot trigger
    // another sync; a hard time cap would merely leave SHARMAT's consumers stale during fast scenes.
    if ($prevValue >= 0 && abs($oslaValue - $prevValue) < 4 && (time() - $prevTs) < 300) { return; }
    $GLOBALS["db"]->insert(
        'responselog',
        [
            'localts' => time(),
            'sent'    => 0,
            'actor'   => $actorName,
            'text'    => 'ExtCmdSyncArousal@' . $oslaValue,
            'action'  => 'command',
            'tag'     => '',
        ]
    );
    aiagentNsfwRuntimeStateSet('osla_sync', $actorName, ['value' => $oslaValue, 'ts' => time()], 86400);
    error_log("[AIAGENTNSFW] SHARMAT arousal authority queued for {$actorName}: canonical " . ($st['sex_disposal'] ?? 0) . " -> game {$oslaValue}");
}

function aiagentNsfwQueueWhiskeyDick($actorName, $localts = null, $param = 'player') {
    $actorName = trim((string)$actorName);
    if (!isset($GLOBALS["db"]) || !$GLOBALS["db"]) {
        return false;
    }
    // DELIVERY (fix 2026-07-02a): the DLL drops command rows whose actor is not a REGISTERED AGENT, and
    // 'The Narrator' never is. Re-route narrator/player/empty to the most recently active registered NPC
    // (an actions_issued actor = an LLM tool caller = a registered agent by definition).
    $playerN = trim((string)($GLOBALS["PLAYER_NAME"] ?? ''));
    $isBadTarget = ($actorName === '')
        || (function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($actorName))
        || ($playerN !== '' && strcasecmp($actorName, $playerN) === 0);
    if ($isBadTarget) {
        $exclP = $GLOBALS["db"]->escape($playerN);
        $row = $GLOBALS["db"]->fetchOne(
            "SELECT actorname FROM public.actions_issued
             WHERE actorname <> '' AND actorname NOT ILIKE '%narrator%'
               AND ('{$exclP}' = '' OR actorname NOT ILIKE '{$exclP}')
             ORDER BY rowid DESC LIMIT 1");
        $cand = trim((string)($row['actorname'] ?? ''));
        if ($cand === '') {
            error_log("[AIAGENTNSFW] WhiskeyDick delivery: no registered agent found to carry the command - notification skipped (impotence window still active server-side)");
            return false;
        }
        error_log("[AIAGENTNSFW] WhiskeyDick delivery re-routed from '{$actorName}' to registered agent '{$cand}'");
        $actorName = $cand;
    }
    $GLOBALS["db"]->insert(
        'responselog',
        [
            'localts' => $localts ?? time(),
            'sent'    => 0,
            'actor'   => $actorName,
            'text'    => "",
            'action'  => 'command|ExtCmdWhiskeyDick@' . ($param === 'stopscene' ? 'stopscene' : 'player'), // '@param' REQUIRED by DLL parseCommand; 'stopscene' only from in-scene sites (fix 2026-07-02b)
            'tag'     => '',
        ]
    );
    error_log("[AIAGENTNSFW] Queued ExtCmdWhiskeyDick for {$actorName}");
    return true;
}

function aiagentNsfwWhiskeyDickChance($drinkStage) {
    $drinkStage = (int)$drinkStage;
    if ($drinkStage >= 6) return max(0, min(100, (int)_getNsfwSetting('WHISKEY_DICK_CHANCE_6', 100)));
    if ($drinkStage >= 5) return max(0, min(100, (int)_getNsfwSetting('WHISKEY_DICK_CHANCE_5', 75)));
    if ($drinkStage >= 4) return max(0, min(100, (int)_getNsfwSetting('WHISKEY_DICK_CHANCE_4', 50)));
    if ($drinkStage >= 3) return max(0, min(100, (int)_getNsfwSetting('WHISKEY_DICK_CHANCE_3', 25)));
    return 0;
}

function aiagentNsfwMaybeTriggerWhiskeyDick($actorName, $inSexScene) {
    if (!$inSexScene || !_getNsfwSetting('WHISKEY_DICK_ENABLED', false)) {
        return false;
    }
    // Whiskey dick is a MALE-PLAYER mechanic (user directive 2026-06-29): a female player has no schlong to fail.
    // PLAYER_SEX: 0 = male, 1 = female. Skip entirely for a female player so the window/notification never fire.
    if (!aiagentNsfwPlayerHasSchlong()) {
        return false;
    }
    $status = getIntimacyForActor($actorName);
    if (!empty($status["is_npc_scene"]) || !empty($status["whiskey_dick_fired"])) {
        return !empty($status["whiskey_dick_fired"]);
    }

    // WINDOW RE-ASSERT (user directive 2026-07-01): a scene STARTED while the impotence window is already open
    // must stall + auto-end + notify too. Without this, only the drink-stage roll could fire - once the 5-min
    // drink window emptied, a fresh scene inside the 10-min whiskey window proceeded normally and silently.
    if (function_exists('aiagentNsfwWhiskeyDickActive') && aiagentNsfwWhiskeyDickActive()) {
        $status["whiskey_dick_fired"] = true;
        $status["scene_phase"] = "whiskey_dick";
        // accepted_sex intentionally NOT cleared (fix 2026-07-01b): wiping it inverted given consent and made every
        // later orgasm in the stalled scene fire the NON-CONSENT boundary cue, poisoning relationship summaries.
        $status["sex_started"] = false;
        updateIntimacyForActor($actorName, $status);
        if (_getNsfwSetting('WHISKEY_DICK_AUTO_END_SCENE', true)) {
            aiagentNsfwQueueWhiskeyDick($actorName, null, 'stopscene'); // in-scene: carrier IS the player's partner - stop the player's scene
        }
        error_log("[AIAGENTNSFW] Whiskey window active - new scene with {$actorName} stalled, auto-end + notification queued");
        return true;
    }

    $playerName = $GLOBALS["PLAYER_NAME"] ?? "Player";
    $playerDrunkStage = function_exists('getDrunkStageForActor') ? getDrunkStageForActor($playerName) : 0;
    if ($playerDrunkStage <= 0 && function_exists('aiagentNsfwPlayerDrinkStage')) {
        // The player never issues Drink/Toast LLM actions, so getDrunkStageForActor() is always 0 for the player.
        // Fall back to the ext_nsfw_player_drink rolling window so pre-scene drinking still gates the in-scene roll.
        $playerDrunkStage = aiagentNsfwPlayerDrinkStage();
    }
    $chance = aiagentNsfwWhiskeyDickChance($playerDrunkStage);
    if ($chance <= 0) {
        return false;
    }
    if ($chance < 100 && random_int(1, 100) > $chance) {
        return false;
    }

    $status["whiskey_dick_fired"] = true;
    $status["whiskey_dick_player_stage"] = $playerDrunkStage;
    $status["scene_phase"] = "whiskey_dick";
    // accepted_sex intentionally NOT cleared (fix 2026-07-01b): wiping it inverted given consent and made every
    // later orgasm in the stalled scene fire the NON-CONSENT boundary cue, poisoning relationship summaries.
    $status["sex_started"] = false;
    updateIntimacyForActor($actorName, $status);
    aiagentNsfwStampWhiskeyDick(); // open the impotence window (duration = NSFW_WHISKEY_DICK_DURATION_MINUTES)

    if (_getNsfwSetting('WHISKEY_DICK_AUTO_END_SCENE', true)) {
        aiagentNsfwQueueWhiskeyDick($actorName, null, 'stopscene'); // in-scene trigger - stop the player's scene
    }
    error_log("[AIAGENTNSFW] Whiskey dick triggered for player with {$actorName}: player alcohol stage {$playerDrunkStage}, chance {$chance}");
    return true;
}

// Read the PLAYER's current alcohol "drink stage" (count of alcoholic drinks inside the rolling window) from the
// ext_nsfw_player_drink tracking file. Shared by the on-drink roll and the in-scene whiskey-dick gate so both agree.
function aiagentNsfwPlayerDrinkStage() {
    $windowMin = max(1, (int)(function_exists('_getNsfwSetting') ? _getNsfwSetting('NSFW_PLAYER_DRUNK_WINDOW_MINUTES', 5) : 5));
    $file = sys_get_temp_dir() . "/nsfw_player_drinks.txt";
    $now = time();
    $cutoff = $now - ($windowMin * 60);
    $n = 0;
    foreach ((@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $l) {
        $t = (int)trim($l);
        if ($t >= $cutoff && $t <= $now) { $n++; }
    }
    return $n;
}

// PLAYER ALCOHOL TRACKING + ON-DRINK WHISKEY DICK (user directive 2026-07-01). The drunk system only tracked NPCs
// (they issue Drink/Toast LLM actions); the PLAYER's own drinking was never recorded, so player-drunk-stage was always
// 0 and whiskey dick could NEVER fire. The Papyrus player alias now reports each drink via the ext_nsfw_player_drink
// fast event; this records the player's alcohol drinks in a real-time rolling window (self-contained - no dependency
// on gamets, which fast events don't carry) and rolls whiskey dick THE MOMENT the player drinks enough - not gated on
// being in a sex scene (you can't drink mid-scene). On a hit it opens the impotence window and queues the Papyrus
// "Sharmat- You have Whiskey Dick!" notification (+ SOS flaccid) so the player is told right away.
function aiagentNsfwRecordPlayerDrink($itemName) {
    $itemName = trim((string)$itemName);
    if ($itemName === '') { return; }
    // Only ALCOHOL counts toward whiskey dick (server owns the authoritative regex; Papyrus reports every drink/potion).
    $rx = function_exists('aiagentNsfwAlcoholRegex') ? aiagentNsfwAlcoholRegex() : 'wine|ale|mead|beer|brandy|rum|grog|liquor|spirits|whisk|vodka|gin|sujamma|mazte|shein|flin';
    if (!@preg_match('/(' . $rx . ')/i', $itemName)) {
        error_log("[AIAGENTNSFW] Player consumed non-alcohol '{$itemName}' - ignored for whiskey dick");
        return;
    }
    $windowMin = max(1, (int)(function_exists('_getNsfwSetting') ? _getNsfwSetting('NSFW_PLAYER_DRUNK_WINDOW_MINUTES', 5) : 5));
    $file = sys_get_temp_dir() . "/nsfw_player_drinks.txt";
    $now = time();
    $cutoff = $now - ($windowMin * 60);
    // flock-protected read-modify-write: concurrent Apache workers handling back-to-back drink events would otherwise
    // race on this file (both read [t1], both write [t1,now]) and LOSE a drink, keeping the stage below 3 forever.
    $kept = [];
    $fh = @fopen($file, 'c+');
    if ($fh) {
        @flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        foreach (array_filter(explode("\n", (string)$raw), 'strlen') as $l) {
            $t = (int)trim($l);
            if ($t >= $cutoff && $t <= $now) { $kept[] = $t; }
        }
        $kept[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, implode("\n", $kept) . "\n");
        @flock($fh, LOCK_UN);
        fclose($fh);
    } else {
        $kept[] = $now;
    }
    $stage = count($kept);
    error_log("[AIAGENTNSFW] Player drank alcohol '{$itemName}' - player drink stage now {$stage} (rolling {$windowMin}m window)");
    aiagentNsfwMaybeTriggerWhiskeyDickOnDrink($stage);
}

// ============================================================================
// ASSAULT-WITNESS LINES (user directive 2026-07-01)
// Surrounding NPCs who can PERCEIVE an ongoing assault get an ambient witness line. Two triggers, wired at their call
// sites: (1) a refusal during a PLAYER sex scene -> "forcing themselves on"; (2) repeated breast grabs of a
// Friendly-and-below NPC -> "grabbing breast" then "playing with titties". Delivery is SILENT (an ambient infoaction
// context line, NOT spoken) and STRICTLY spatial: only NPCs the CORE spatial system reports as perceiving the victim
// receive it - no spatial evidence => nobody hears => nothing is emitted. Master toggle: ENABLE_WITNESS_LINES.
// ----------------------------------------------------------------------------

// Master on/off for the whole witness feature (UI checkbox under Refusal Prompts). Stored as 'enable_witness_lines'
// in the aiagent_nsfw_prompts blob and read directly + bool-cast (mirrors isNonConsentPromptEnabled) - NOT via
// _getNsfwPromptSetting, whose !empty check would misread a stored false as the default. Default ON.
function aiagentNsfwWitnessLinesEnabled() {
    static $cached = null;
    if ($cached !== null) { return $cached; }
    try {
        if (isset($GLOBALS["db"]) && $GLOBALS["db"]) {
            $row = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_prompts'");
            if ($row && !empty($row['value'])) {
                $prompts = json_decode($row['value'], true);
                if (is_array($prompts) && isset($prompts['enable_witness_lines'])) {
                    $cached = (bool)$prompts['enable_witness_lines'];
                    return $cached;
                }
            }
        }
    } catch (Exception $e) {
        error_log("[AIAGENTNSFW] Error checking enable_witness_lines: " . $e->getMessage());
    }
    $cached = true; // default enabled
    return $cached;
}

// Rolling 10-second breast-grab counter, per (player,victim), flock-protected (mirrors aiagentNsfwRecordPlayerDrink so
// concurrent Apache workers handling back-to-back grabs can't race and lose one). Returns the grab count in the window.
function aiagentNsfwRecordBreastGrab($playerName, $victimNpc) {
    $key    = md5(strtolower(trim((string)$playerName) . '|' . trim((string)$victimNpc)));
    $file   = sys_get_temp_dir() . "/nsfw_breast_grab_{$key}.txt";
    $now    = time();
    $cutoff = $now - 10; // HARDCODED 10-second window (user directive)
    $kept   = [];
    $fh = @fopen($file, 'c+');
    if ($fh) {
        @flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        foreach (array_filter(explode("\n", (string)$raw), 'strlen') as $l) {
            $t = (int)trim($l);
            if ($t >= $cutoff && $t <= $now) { $kept[] = $t; }
        }
        $kept[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, implode("\n", $kept) . "\n");
        @flock($fh, LOCK_UN);
        fclose($fh);
    } else {
        $kept[] = $now;
    }
    return count($kept);
}

// Emit ONE silent ambient witness line to EACH surrounding NPC that can perceive the victim, respecting the core
// spatial-awareness gate. $witnessType in {forcing, breast_grab, breast_play}. Affinity gating is the CALLER's job.
function aiagentNsfwEmitWitnessLine($witnessType, $victimNpc, $playerName) {
    if (!aiagentNsfwWitnessLinesEnabled()) { return; }
    $victimNpc  = trim((string)$victimNpc);
    $playerName = trim((string)$playerName);
    if ($victimNpc === '' || $playerName === '') { return; }

    // 1) Editable prompt text (UI, under Refusal Prompts). Defaults baked in so the feature works before any UI save.
    $defaults = [
        'witness_forcing'     => '#PLAYER_NAME# is sexually forcing themselves on #NPC_NAME#.',
        'witness_breast_grab' => '#PLAYER_NAME# is sexually assaulting #NPC_NAME# - grabbing breast.',
        'witness_breast_play' => '#PLAYER_NAME# is sexually assaulting #NPC_NAME# - playing with titties.',
    ];
    $promptKey = 'witness_' . $witnessType;
    if (!isset($defaults[$promptKey])) { return; }
    $tpl = function_exists('_getNsfwPromptSetting')
        ? trim((string)_getNsfwPromptSetting($promptKey, $defaults[$promptKey]))
        : $defaults[$promptKey];
    if ($tpl === '') { return; }

    // 2) Per-(victim,type) throttle so a burst of grabs can't spam identical lines (escalation tiers use different
    //    keys, so grab -> play is NOT swallowed).
    $throttleFile = sys_get_temp_dir() . "/nsfw_witness_" . md5(strtolower($victimNpc . '|' . $witnessType)) . ".txt";
    if ((time() - (int)(@file_get_contents($throttleFile) ?: 0)) < 8) { return; }
    @file_put_contents($throttleFile, time(), LOCK_EX);

    // 3) Who can PERCEIVE it? Core spatial gate (strict-spatial by default: no evidence => empty => no witnesses).
    if (!function_exists('buildScopedPeopleFromSpatialEvidence')) { return; }
    $scoped = buildScopedPeopleFromSpatialEvidence('infoaction', "{$playerName} is with {$victimNpc}", $victimNpc, "");
    $candidates = array_filter(array_map('trim', explode('|', (string)$scoped)), 'strlen');
    if (empty($candidates)) { return; }

    // 4) Exclude the victim, the player, and the victim's own scene participants (co-participants are not witnesses).
    $exclude = [strtolower($victimNpc) => true, strtolower($playerName) => true];
    if (function_exists('getIntimacyForActor')) {
        $vi = getIntimacyForActor($victimNpc);
        if (is_array($vi) && is_array($vi['scene_actors'] ?? null)) {
            foreach ($vi['scene_actors'] as $sa) { $exclude[strtolower(trim((string)$sa))] = true; }
        }
    }

    // 5) Emit one silent infoaction per surviving witness, with the witness name forced into eventlog.people so ONLY
    //    that NPC ever reads it (per-NPC spatial guarantee; see chat_helper_functions.php logEvent $forcePeople path).
    $ts   = (int)($GLOBALS["gameRequest"][1] ?? time());
    $gts  = (float)($GLOBALS["gameRequest"][2] ?? 0);
    $line = str_replace(['#PLAYER_NAME#', '#NPC_NAME#'], [$playerName, $victimNpc], $tpl);
    $emitted = 0;
    foreach ($candidates as $w) {
        if (isset($exclude[strtolower($w)])) { continue; }
        if (function_exists('getIntimacyForActor')) { // don't bulletin an NPC who is themselves mid-scene
            $wi = getIntimacyForActor($w);
            $wp = is_array($wi) ? ($wi['scene_phase'] ?? null) : null;
            if ($wp === 'accepted' || $wp === 'engaged') { continue; }
        }
        if (function_exists('logEvent')) {
            logEvent(["infoaction", $ts, $gts, "(" . $line . ")"], "|" . $w . "|");
            $emitted++;
        }
    }
    error_log("[AIAGENTNSFW] Witness line '{$witnessType}' re: {$victimNpc}: emitted to {$emitted} spatially-perceiving NPC(s)");
}

// Roll whiskey dick from the player's current drink stage, independent of any sex scene. Same enable/male/chance gates
// as the in-scene path, but fires on the drink itself with an immediate notification.
function aiagentNsfwMaybeTriggerWhiskeyDickOnDrink($stage) {
    if (!(function_exists('_getNsfwSetting') && _getNsfwSetting('WHISKEY_DICK_ENABLED', false))) { return false; }
    if (!aiagentNsfwPlayerHasSchlong()) { return false; }                         // no schlong to fail
    if (function_exists('aiagentNsfwWhiskeyDickActive') && aiagentNsfwWhiskeyDickActive()) { return true; } // window already open
    $chance = function_exists('aiagentNsfwWhiskeyDickChance') ? (int)aiagentNsfwWhiskeyDickChance((int)$stage) : 0;
    if ($chance <= 0) { return false; }                                             // below the drink threshold (stage < 3)
    if ($chance < 100 && random_int(1, 100) > $chance) {
        error_log("[AIAGENTNSFW] Whiskey dick roll FAILED on drink: stage {$stage}, chance {$chance}%");
        return false;
    }
    if (function_exists('aiagentNsfwStampWhiskeyDick')) { aiagentNsfwStampWhiskeyDick(); } // open the impotence window
    // Route the player-facing notification command through a present NPC if there is one, else the player. The Papyrus
    // ExtCmdWhiskeyDick handler shows "Sharmat- You have Whiskey Dick!" + limps SOS regardless of which actor carries it.
    $notifyActor = trim((string)($GLOBALS["HERIKA_NAME"] ?? ""));
    if ($notifyActor === "" || strcasecmp($notifyActor, "(actor)") === 0) { $notifyActor = $GLOBALS["PLAYER_NAME"] ?? "Player"; }
    if (function_exists('aiagentNsfwQueueWhiskeyDick')) { aiagentNsfwQueueWhiskeyDick($notifyActor, null, 'player'); } // on-drink: notification+flaccid ONLY, no scene stop
    error_log("[AIAGENTNSFW] Whiskey dick TRIGGERED on drink: stage {$stage}, chance {$chance}% -> notification queued via {$notifyActor}");
    return true;
}

// WHISKEY DICK DURATION WINDOW (user directive 2026-06-29): once triggered, the player "has whiskey dick" for
// NSFW_WHISKEY_DICK_DURATION_MINUTES real minutes (~3 in-game hours at default timescale). During the window the
// sex-scene initiators are withheld (he can't perform) and the schlong is limped via the SOS animation event.
// Stored as a global /tmp marker so it persists past the scene that triggered it.
function aiagentNsfwStampWhiskeyDick() {
    $mins = max(1, (int)_getNsfwSetting('NSFW_WHISKEY_DICK_DURATION_MINUTES', 10));
    @file_put_contents(sys_get_temp_dir() . "/nsfw_whiskey_dick_until.txt", time() + $mins * 60);
}
function aiagentNsfwWhiskeyDickActive() {
    if (!_getNsfwSetting('WHISKEY_DICK_ENABLED', false)) { return false; }
    $f = sys_get_temp_dir() . "/nsfw_whiskey_dick_until.txt";
    if (!is_file($f)) { return false; }
    return ((int)(@file_get_contents($f) ?: 0)) > time();
}

// AFFECTION SPAM THROTTLE (user directive 2026-06-29): the legacy COOLDOWNMAP is never consumed anywhere, so affection
// had NO working cooldown and the model spammed HoldHands / Hug / Kiss (each starting an OStim alignment scene). When
// NSFW_AFFECTION_COOLDOWN_ENABLED is on (default), an NPC's affection acts are withheld for a short fixed window after
// she last used one, so they cannot fire back to back. Per-NPC, tracked via a /tmp marker; no DB round-trip.
function aiagentNsfwStampAffection($npcName) {
    $npcName = trim((string)$npcName);
    if ($npcName === '') { return; }
    @file_put_contents(sys_get_temp_dir() . "/nsfw_affection_" . md5(strtolower($npcName)) . ".txt", time());
}
function aiagentNsfwAffectionOnCooldown($npcName) {
    if (!_getNsfwSetting('NSFW_AFFECTION_COOLDOWN_ENABLED', true)) { return false; }
    $npcName = trim((string)$npcName);
    if ($npcName === '') { return false; }
    $f = sys_get_temp_dir() . "/nsfw_affection_" . md5(strtolower($npcName)) . ".txt";
    if (!is_file($f)) { return false; }
    $last = (int)(@file_get_contents($f) ?: 0);
    return ($last > 0 && (time() - $last) < 60); // fixed 60s affection cooldown when the checkbox is on
}

// RELATIONSHIP-TYPE SEX-ELIGIBILITY GATE (user directive 2026-06-29): an NPC is sexually available ONLY if her
// relationship TYPE with the player is one of the UI-selected eligible types (aiagent_nsfw_reltypes.eligible_types,
// e.g. romantic/crush). Affinity does NOT override this - a Bonded "student" still refuses. Returns true=eligible.
// Fails OPEN (returns true) whenever the gate can't be evaluated (gate disabled, no types configured, DB/Relationship
// unreadable) so it can never accidentally lock out everyone. A KNOWN type not in the list (incl. empty/unknown when
// the gate is configured) is ineligible. Slaves/prostitutes/skooma bypass this in the caller, not here.
// === OPEN MODE (2026-07-10) =========================================================
// One master switch that turns the consent/eligibility FRAMEWORK off while leaving the
// information machinery (scene state, tiers, physics, gaze, drugs, fertility) running:
// anyone adult can engage anyone adult. Explicit adult, non-commercial player scenes enter
// accepted state directly; RefuseSex stays available for genuine in-character agency and any
// sticky refusal still dominates. CHILD PROTECTION IS NEVER
// PART OF THIS SWITCH - every child gate checks independently of open mode. Relationship
// learning keeps running in the background so turning open mode off later lands in a
// coherent world. PROSTITUTES STILL REQUIRE PAYMENT (user directive 2026-07-10): the
// payment branch in prerequest's arousal block is untouched; open mode only skips the
// arousal threshold for everyone else. Checked at the choke points:
// aiagentNsfwRelTypeSexEligible, aiagentNsfwOrientationAllowsPlayer,
// aiagentNsfwNpcToNpcSexEligible, the stranger fallback gate (functions.php), and the
// two arousal-gate sites in prerequest.php (warmup decline + engage threshold), the player-scene
// acceptance choke point, and the Open Mode tier-cue replacement.
function aiagentNsfwOpenMode() {
    static $cached = null;
    if ($cached === null) { $cached = (bool)_getNsfwSetting('NSFW_OPEN_MODE', false); }
    return $cached;
}

// === PROMISCUOUS (SLUT) MARK (2026-07-10, PER-NPC ONLY - the short-lived global
// NSFW_SLUT_MODE switch was removed the same day per user directive) ==============
// is_slut on the NPC Settings tab: a third role mark beside prostitute and slave.
// The ladder is compressed FOR THIS NPC: eligibility becomes affinity-tier driven from
// ACQUAINTANCE (6) up - the rel-type grid, the Friendly(31)/affair floors, and the
// scene-call floor do not apply to her (see aiagentNsfwSceneCallFloorFor). Below
// Acquaintance (strangers, neutral, disliked) she still refuses. Arousal, payment,
// orientation, combat block, and child protection all behave exactly as normal. Her
// prompt framing comes from relationship_overhead_slut_<tier> + slut_role_context.
function aiagentNsfwIsSlutNpc($npcName) {
    $npcName = trim((string)$npcName);
    if ($npcName === '') { return false; }
    static $cache = [];
    if (array_key_exists($npcName, $cache)) { return $cache[$npcName]; }
    $flag = false;
    try {
        require_once __DIR__ . "/nsfw_data.php";
        $ext = NsfwNpcData::get($npcName);
        $flag = !empty($ext['is_slut']);
    } catch (Throwable $t) { $flag = false; }
    if ($flag && function_exists('aiagentNsfwIsProtectedNpc') && aiagentNsfwIsProtectedNpc($npcName)) { $flag = false; }
    $cache[$npcName] = $flag;
    return $flag;
}

// Effective scene-call affinity floor for THIS NPC: a promiscuous-marked NPC drops to the
// Acquaintance boundary (6) unless the user set the global slider even lower (min, never
// raised). Everyone else gets the slider value. Use this instead of reading
// NSFW_SCENE_CALL_MIN_AFFINITY directly wherever a specific NPC is being gated.
function aiagentNsfwSceneCallFloorFor($npcName) {
    $floor = (int)_getNsfwSetting('NSFW_SCENE_CALL_MIN_AFFINITY', 56);
    if (aiagentNsfwIsSlutNpc($npcName)) { $floor = min($floor, 6); }
    return $floor;
}

// Effective AFFECTION floor (Kiss/Hug/HoldHands) for THIS NPC: promiscuous-marked NPCs drop to
// the Acquaintance boundary (6) - unlocking full sex at Acquainted while kissing stayed locked
// until Fond was backwards. Everyone else keeps the historical Fond(56) floor.
function aiagentNsfwAffectionFloorFor($npcName) {
    return aiagentNsfwIsSlutNpc($npcName) ? 6 : 56;
}

// === RUNTIME STATE IN conf_opts (2026-07-11, Tyler's upstream review: "avoid using files here -
// conf_opts was created for that; value can be a json object"). One row holds SHARMAT's small
// transient runtime state (VR last-contact snapshots, sustained-touch accumulators, gaze
// cooldowns), shaped bucket -> key -> {ts, ttl, v}. Expired entries are pruned on every write so
// the row never grows. tmp files died on reboot; conf_opts persists - harmless, every reader
// already applies its own freshness window on top. In-request static cache, write-through.
// Known tradeoff vs per-key tmp files: two SIMULTANEOUS requests can race the read-modify-write
// and drop each other's entry - acceptable, this is disposable debounce/cooldown state.
function _aiagentNsfwRuntimeState($write = null) {
    static $state = null;
    if (!isset($GLOBALS['db'])) { return is_array($write) ? $write : []; }
    if ($state === null) {
        $state = [];
        try {
            $row = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_runtime_state'");
            if ($row && !empty($row['value'])) {
                $decoded = json_decode($row['value'], true);
                if (is_array($decoded)) { $state = $decoded; }
            }
        } catch (Exception $e) { $state = []; }
    }
    if (is_array($write)) {
        $state = $write;
        try {
            $escaped = $GLOBALS['db']->escape(json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $GLOBALS['db']->execQuery("INSERT INTO conf_opts (id, value) VALUES ('aiagent_nsfw_runtime_state', '{$escaped}') ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value");
        } catch (Exception $e) { /* non-fatal: this is debounce/cooldown state */ }
    }
    return $state;
}

function aiagentNsfwRuntimeStateGet($bucket, $key) {
    $state = _aiagentNsfwRuntimeState();
    $entry = $state[$bucket][strtolower(trim((string)$key))] ?? null;
    if (!is_array($entry)) { return null; }
    if ((time() - (int)($entry['ts'] ?? 0)) > (int)($entry['ttl'] ?? 3600)) { return null; } // expired, prune on next write
    return $entry['v'] ?? null;
}

function aiagentNsfwRuntimeStateSet($bucket, $key, $value, $ttlSeconds = 3600) {
    $state = _aiagentNsfwRuntimeState();
    $now = time();
    foreach ($state as $b => $entries) {   // prune everything expired so the row never grows
        if (!is_array($entries)) { unset($state[$b]); continue; }
        foreach ($entries as $k => $e) {
            if (!is_array($e) || ($now - (int)($e['ts'] ?? 0)) > (int)($e['ttl'] ?? 3600)) { unset($state[$b][$k]); }
        }
        if (empty($state[$b])) { unset($state[$b]); }
    }
    $state[$bucket][strtolower(trim((string)$key))] = ['ts' => $now, 'ttl' => max(60, (int)$ttlSeconds), 'v' => $value];
    _aiagentNsfwRuntimeState($state);
}

// Apply the last body report from the game to every request. Vanilla sex drives
// gender/orientation. OStim/SOS anatomy separately drives genital references.
function aiagentNsfwApplyPlayerBodyGlobals() {
    $body = aiagentNsfwRuntimeStateGet('player', 'body');
    if (!is_array($body) || !array_key_exists('sex', $body)) { return; }

    $sex = ((int)$body['sex'] === 1) ? 1 : 0;
    $GLOBALS['PLAYER_SEX'] = $sex;
    $GLOBALS['PLAYER_GENDER'] = $sex === 1 ? 'female' : 'male';
    $GLOBALS['PLAYER_HAS_SCHLONG'] = !empty($body['has_schlong']);
}

function aiagentNsfwPlayerHasSchlong() {
    if (array_key_exists('PLAYER_HAS_SCHLONG', $GLOBALS)) {
        return !empty($GLOBALS['PLAYER_HAS_SCHLONG']);
    }
    // Legacy fallback before the first report arrives.
    return (int)($GLOBALS['PLAYER_SEX'] ?? 0) === 0;
}

aiagentNsfwApplyPlayerBodyGlobals();

// Full reset (game load / new session): wipe the runtime-state row and the contact ledger row.
// Goes through the writers where possible so in-request static caches stay coherent.
function aiagentNsfwRuntimeStateClear() {
    _aiagentNsfwRuntimeState([]);
    if (function_exists('aiagentNsfwContactSaveLedger')) {
        aiagentNsfwContactSaveLedger([]);
    } elseif (isset($GLOBALS['db'])) {
        try { $GLOBALS['db']->delete("conf_opts", "id = 'aiagent_nsfw_contact_state'"); } catch (Exception $e) { /* non-fatal */ }
    }
}

function aiagentNsfwRelTypeSexEligible($npcName) {
    $npcName = trim((string)$npcName);
    if ($npcName === '') { return true; }
    if (function_exists('aiagentNsfwIsProtectedNpc') && aiagentNsfwIsProtectedNpc($npcName)) { return false; }
    // RELDYN CONSENT (LOCAL hook, branch reldyn-consent; Ken 2026-10-01: Sharmat handles the "during", RelDyn decides whether it gets there
    // and its decision supersedes ours). When RelDyn is installed and has published its decision for this NPC, that decision is the answer:
    // no => not eligible (every consumer of this gate already turns that into stripped tools, a kind decline and no held acceptance);
    // yes => the rel-type grid, affinity floors and open mode are not asked again (orientation and the affair floor, which RelDyn does not
    // read, still can say no). Returns null without RelDyn or without a decision, and the gate below runs exactly as before.
    if (function_exists('aiagentNsfwRelDynConsentEligibility')) {
        $relDynEligible = aiagentNsfwRelDynConsentEligibility($npcName);
        if ($relDynEligible !== null) { return $relDynEligible; }
    }
    // OPEN MODE: rel types and affinity floors do not gate (children stay gated by their own frame).
    if (aiagentNsfwOpenMode() && !(function_exists('aiagentNsfwIsChildNpc') && aiagentNsfwIsChildNpc($npcName))) { return true; }
    // PROMISCUOUS (SLUT) MARK: eligibility is affinity-tier driven from Acquaintance (6) up - the
    // rel-type grid and the Friendly/affair floors do not apply to her. Below Acquaintance still
    // refuses (the ladder starts lower, it does not disappear). Slave/prostitute lanes and
    // everything downstream behave as normal. Children never carry the mark (checked inside).
    if (aiagentNsfwIsSlutNpc($npcName)) {
        // ORIENTATION still walls the player lane (fail-open like everywhere else): the mark removes
        // the type grid, so orientation is the only compatibility check left for her. Explicit
        // mismatch (straight + same-sex player, gay + opposite) or asexual blocks; unknown/bi/pan pass.
        if (function_exists('aiagentNsfwOrientationAllowsPlayer') && !aiagentNsfwOrientationAllowsPlayer($npcName)) {
            error_log("[AIAGENTNSFW] SLUT (promiscuous mark) eligibility {$npcName}: orientation mismatch => NOT eligible");
            return false;
        }
        $smAff = function_exists('getNpcAffinity') ? (int)getNpcAffinity($npcName) : 0;
        $smOk = ($smAff >= 6);
        error_log("[AIAGENTNSFW] SLUT (promiscuous mark) eligibility {$npcName}: aff={$smAff}/floor=6(acquaintance) => " . ($smOk ? 'ELIGIBLE (sex allowed)' : 'NOT eligible (should refuse)'));
        return $smOk;
    }
    $eligible = [];
    try {
        $row = (isset($GLOBALS["db"]) && $GLOBALS["db"]) ? $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_reltypes'") : null;
        if ($row && !empty($row['value'])) {
            $cfg = json_decode($row['value'], true);
            if (is_array($cfg)) {
                if (array_key_exists('enabled', $cfg) && !$cfg['enabled']) { return true; }
                if (is_array($cfg['eligible_types'] ?? null)) { $eligible = array_map('strtolower', $cfg['eligible_types']); }
            }
        }
    } catch (Exception $e) { return true; }
    if (empty($eligible)) { return true; }
    $relType = '';
    try {
        if (class_exists('RelationshipManager')) {
            $rel = RelationshipManager::getPlayerRelationship($npcName);
            $relType = aiagentNsfwCanonicalRelationshipType($rel['type'] ?? '');
        } else {
            error_log("[AIAGENTNSFW] rel-type gate: RelationshipManager class NOT available for {$npcName} - failing OPEN (eligible)");
            return true;
        }
    } catch (Exception $e) {
        error_log("[AIAGENTNSFW] rel-type gate exception for {$npcName}: " . $e->getMessage() . " - failing OPEN");
        return true;
    }
    $result = ($relType !== '' && in_array($relType, $eligible, true));
    // AFFINITY FLOOR (user directive 2026-07-02): the ability to EVER consent requires BOTH the eligible
    // rel type AND at least the scene-call affinity floor (default Fond). Below the floor an eligible
    // type is treated exactly like an ineligible one - tools stripped, decline directive, no consent.
    $affNote = '';
    if ($result) {
        // TIER LADDER (user directive 2026-07-02): below FRIENDLY nobody can consent. A married NPC whose
        // spouse is not the player cannot consent below DEVOTED - the affair decision only exists at
        // Devoted+. (Model-driven consent additionally keeps the Fond scene-call floor in the consent formula.)
        $affFloor = 31; $affWhy = 'friendly';
        try {
            require_once __DIR__ . "/nsfw_data.php";
            $eligNsfw = NsfwNpcData::get($npcName);
            $eligSpouse = trim((string)($eligNsfw['spouse_names'] ?? ''));
            $eligStatus = strtolower(trim((string)($eligNsfw['spousal_status'] ?? '')));
            $eligPlayer = trim((string)($GLOBALS['PLAYER_NAME'] ?? ''));
            $eligMarried = ($eligSpouse !== '') || in_array($eligStatus, ['married', 'betrothed'], true);
            $eligToPlayer = ($eligSpouse !== '' && $eligPlayer !== '' && stripos($eligSpouse, $eligPlayer) !== false);
            if ($eligMarried && !$eligToPlayer) {
                // Affair floor is UI-tunable (user directive 2026-07-04, was hardcoded Devoted 76).
                $affFloor = (int)aiagentNsfwArousalNum('NSFW_AFFAIR_MIN_AFFINITY', 56);
                $affWhy = 'affair floor (married to another)';
            }
        } catch (Throwable $t) { /* profile unavailable - base Friendly floor applies */ }
        $affVal = (int)($rel['aff'] ?? 0);
        $affNote = " aff={$affVal}/floor={$affFloor}({$affWhy})";
        if ($affVal < $affFloor) { $result = false; }
    }
    error_log("[AIAGENTNSFW] rel-type eligibility {$npcName}: type='{$relType}'{$affNote} eligible=[" . implode(',', $eligible) . "] => " . ($result ? 'ELIGIBLE (sex allowed)' : 'NOT eligible (should refuse)'));
    return $result;
}

function aiagentNsfwQueueNpcSceneStop($actorName, $threadID, $localts = null) {
    $actorName = trim((string)$actorName);
    $threadID = (int)$threadID;

    if ($actorName === '' || $threadID < 0) {
        error_log("[AIAGENTNSFW] NPC scene-stop skipped for {$actorName}: invalid thread {$threadID}");
        return false;
    }
    if (aiagentNsfwSceneExitLocked($actorName)) {
        error_log("[AIAGENTNSFW] NPC scene-stop SUPPRESSED for {$actorName} (drunk/sap exit-lock) - refusal stands, scene continues");
        return false;
    }

    $GLOBALS["db"]->insert(
        'responselog',
        [
            'localts' => $localts ?? time(),
            'sent'    => 0,
            'actor'   => $actorName,
            'text'    => 'ExtCmdStopNpcScene@' . $threadID,
            'action'  => 'command',
            'tag'     => '',
        ]
    );
    error_log("[AIAGENTNSFW] Queued ExtCmdStopNpcScene hard stop for {$actorName} (thread {$threadID})");
    return true;
}

function aiagentNsfwQueueSapDropAndRouse($actorName, $refid, $dropLocalts = null, $rouseLocalts = null) {
    $refid = trim((string)$refid);
    if ($actorName === '' || $refid === '') {
        return false;
    }
    if (!class_exists('SkyrimCommandBuilder')) { require_once __DIR__ . "/../../lib/scriptproxy_papyrus.php"; }

    $refidHex = (stripos($refid, '0x') === 0) ? $refid : "0x{$refid}";
    $cmd = new SkyrimCommandBuilder();
    $dropAt = $dropLocalts ?? time();

    $cmd->send($cmd->Actor->ModActorValue($refidHex, "Paralysis", 1), $dropAt);
    $cmd->send($cmd->ObjectReference->PushActorAway($refidHex, $refidHex, 1), $dropAt);
    $cmd->send($cmd->Actor->SetNotShowOnStealthMeter($refidHex, true), $dropAt);
    $cmd->send($cmd->Actor->StopCombat($refidHex), $dropAt);
    $cmd->send($cmd->Actor->SetUnconscious($refidHex, false), $dropAt);

    if ($rouseLocalts !== null && (int)$rouseLocalts > $dropAt) {
        $rouseAt = (int)$rouseLocalts;
        $cmd->send($cmd->Actor->ForceActorValue($refidHex, "Paralysis", 0), $rouseAt);
        $cmd->send($cmd->Actor->SetUnconscious($refidHex, false), $rouseAt);
        $cmd->send($cmd->Actor->SetNotShowOnStealthMeter($refidHex, false), $rouseAt);
        $cmd->send($cmd->Actor->StopCombat($refidHex), $rouseAt);
        $cmd->send($cmd->Actor->EvaluatePackage($refidHex), $rouseAt);
    }

    error_log("[AIAGENTNSFW] Queued sap drop" . ($rouseLocalts !== null ? " and timed rouse" : "") . " for {$actorName}");
    return true;
}

function aiagentNsfwQueueSapRouse($actorName, $refid, $localts = null) {
    $refid = trim((string)$refid);
    if ($actorName === '' || $refid === '') {
        return false;
    }
    if (!class_exists('SkyrimCommandBuilder')) { require_once __DIR__ . "/../../lib/scriptproxy_papyrus.php"; }

    $refidHex = (stripos($refid, '0x') === 0) ? $refid : "0x{$refid}";
    $cmd = new SkyrimCommandBuilder();
    $at = $localts ?? time();
    $cmd->send($cmd->Actor->ForceActorValue($refidHex, "Paralysis", 0), $at);
    $cmd->send($cmd->Actor->SetUnconscious($refidHex, false), $at);
    $cmd->send($cmd->Actor->SetNotShowOnStealthMeter($refidHex, false), $at);
    $cmd->send($cmd->Actor->StopCombat($refidHex), $at);
    $cmd->send($cmd->Actor->EvaluatePackage($refidHex), $at);
    error_log("[AIAGENTNSFW] Queued sap rouse for {$actorName}");
    return true;
}

function aiagentNsfwQueueDrunkRouse($actorName, $refid, $localts = null) {
    $refid = trim((string)$refid);
    if ($actorName === '' || $refid === '') {
        return false;
    }
    if (!class_exists('SkyrimCommandBuilder')) { require_once __DIR__ . "/../../lib/scriptproxy_papyrus.php"; }

    $refidHex = (stripos($refid, '0x') === 0) ? $refid : "0x{$refid}";
    $cmd = new SkyrimCommandBuilder();
    $at = $localts ?? time();
    $cmd->send($cmd->Actor->ForceActorValue($refidHex, "Paralysis", 0), $at);
    $cmd->send($cmd->Actor->SetUnconscious($refidHex, false), $at);
    $cmd->send($cmd->Actor->SetNotShowOnStealthMeter($refidHex, false), $at);
    $cmd->send($cmd->Actor->StopCombat($refidHex), $at);
    $cmd->send($cmd->Actor->EvaluatePackage($refidHex), $at);
    $cmd->send($cmd->Actor->ForceActorValue($refidHex, "Variable10", 0), $at);
    error_log("[AIAGENTNSFW] Queued drunk rouse for {$actorName}");
    return true;
}

function aiagentNsfwQueueSkoomaReset($actorName, $refid, $localts = null) {
    $refid = trim((string)$refid);
    if ($actorName === '' || $refid === '') {
        return false;
    }
    if (!class_exists('SkyrimCommandBuilder')) { require_once __DIR__ . "/../../lib/scriptproxy_papyrus.php"; }

    $refidHex = (stripos($refid, '0x') === 0) ? $refid : "0x{$refid}";
    $drugVar = function_exists('_getNsfwSetting') ? _getNsfwSetting("DRUG_OAR_VARIABLE", "Variable09") : "Variable09";
    $cmd = new SkyrimCommandBuilder();
    $at = $localts ?? time();
    $cmd->send($cmd->Actor->ForceActorValue($refidHex, $drugVar, 0), $at);
    $cmd->send($cmd->Actor->ForceActorValue($refidHex, "SpeedMult", 100), $at);
    $cmd->send($cmd->Actor->EvaluatePackage($refidHex), $at);
    error_log("[AIAGENTNSFW] Queued skooma reset for {$actorName}");
    return true;
}

// Vanilla child NPC names that may lack a reliable is_child flag in nsfw_npc_data.
// Single source of truth for the hard child blocklist (config_manager's isNpcBlockedFromNsfw mirrors it).
if (!function_exists('aiagentNsfwChildNameBlocklist')) {
function aiagentNsfwChildNameBlocklist() {
    return [
        'babette', 'erith', 'blaise', 'clinton lylvieve', 'lucia', 'mila valentia',
        'francois beaufort', 'samuel', 'aeta', 'agni', 'britte', 'dagny', 'dorthe',
        'eirid', 'fjotra', 'helgi', "helgi's ghost", 'hrefna', 'minette vinius',
        'rin', 'runa fair-shield', 'sissel', 'sofie', 'svari', 'assur',
        'aventus aretino', 'bottar', 'frodnar', 'frothar', 'gralnach',
        'grimvar cruel-sea', 'haming', 'hroar', 'joric', 'knud', 'lars battle-born',
        'little pelagius', 'nelkir', 'skuli', 'smaref ice-blade', 'sond', 'virkmund',
        'adara', 'braith', 'alesan', 'kayd', 'lavinia', 'clinton'
    ];
}
}

// Runtime child detection (HARD signals only): vanilla child-name blocklist, the is_child flag,
// or an explicit child race ("Nord Child" etc.). Used by Child Protection (context_pre.php) and
// NSFW gating. Soft/inferred detection + the override UI are a separate, later layer.
if (!function_exists('aiagentNsfwIsChildNpc')) {
function aiagentNsfwIsChildNpc($actorName) {
    $actorName = trim((string)$actorName);
    if ($actorName === '') { return false; }
    if (in_array(strtolower($actorName), aiagentNsfwChildNameBlocklist(), true)) { return true; }
    if (!class_exists('NsfwNpcData')) {
        @require_once __DIR__ . '/nsfw_data.php';
    }
    if (class_exists('NsfwNpcData')) {
        $d = NsfwNpcData::get($actorName);
        if (is_array($d)) {
            if (!empty($d['is_child'])) { return true; }
            $race = strtolower(trim((string)($d['race'] ?? '')));
            if ($race !== '' && strpos($race, 'child') !== false) { return true; }
        }
    }
    return false;
}
}

// User-managed exact-name exclusions. This is separate from child detection: users may add
// modded actors here and remove them later (for example after an FMR child becomes an adult).
if (!function_exists('aiagentNsfwExcludedNpcNames')) {
function aiagentNsfwExcludedNpcNames() {
    $raw = function_exists('_getNsfwSetting') ? (string)_getNsfwSetting('NSFW_EXCLUDED_NPCS', '') : '';
    if (trim($raw) === '') { return []; }
    $names = preg_split('/[\r\n,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $result = [];
    foreach ($names as $name) {
        $name = strtolower(trim((string)$name));
        if ($name !== '') { $result[$name] = true; }
    }
    return array_keys($result);
}
}

if (!function_exists('aiagentNsfwIsExcludedNpc')) {
function aiagentNsfwIsExcludedNpc($actorName) {
    $name = strtolower(trim((string)$actorName));
    return $name !== '' && in_array($name, aiagentNsfwExcludedNpcNames(), true);
}
}

// One hard server-side boundary for every intimacy route. Child race/flag/name protection cannot
// be bypassed; the exact-name exclusion list is controlled by the user.
if (!function_exists('aiagentNsfwIsProtectedNpc')) {
function aiagentNsfwIsProtectedNpc($actorName) {
    return aiagentNsfwIsChildNpc($actorName) || aiagentNsfwIsExcludedNpc($actorName);
}
}

// VAMPIRE DETECTION (user directive 2026-07-01). Skyrim collapses the race to its base name ("Nord"), so the stored
// race string NEVER contains "vampire" (verified: 0/56 NPCs) - race matching is useless here. Instead, the SHARMAT
// Papyrus reports vampire status the same way Fertility Mode Reloaded detects it: akActor.GetRace().HasKeywordString
// ("Vampire") on the RACE FORM (the vampire keyword is attached to the race even when its name is just "Nord"). That
// bool is delivered via the ext_nsfw_vampire fast event and persisted here as extended_data['is_vampire']; this reader
// just consumes the stored flag. Also honors a UI/profile override if one is ever set.
if (!function_exists('aiagentNsfwIsVampireNpc')) {
function aiagentNsfwIsVampireNpc($actorName) {
    $actorName = trim((string)$actorName);
    if ($actorName === '') { return false; }
    if (!class_exists('NsfwNpcData')) {
        @require_once __DIR__ . '/nsfw_data.php';
    }
    if (class_exists('NsfwNpcData')) {
        $d = NsfwNpcData::get($actorName);
        if (is_array($d) && !empty($d['is_vampire'])) { return true; }
    }
    return false;
}
}

// Persist the Papyrus-reported vampire flag onto the NPC's stored profile (from the ext_nsfw_vampire fast event).
if (!function_exists('aiagentNsfwSetVampireFlag')) {
function aiagentNsfwSetVampireFlag($actorName, $isVampire) {
    $actorName = trim((string)$actorName);
    if ($actorName === '') { return; }
    if (!class_exists('NsfwNpcData')) {
        @require_once __DIR__ . '/nsfw_data.php';
    }
    if (!class_exists('NsfwNpcData')) { return; }
    $d = NsfwNpcData::get($actorName);
    if (!is_array($d)) { $d = []; }
    $new = $isVampire ? true : false;
    if ((bool)($d['is_vampire'] ?? false) === $new) { return; }   // no-op if unchanged (avoid needless writes)
    $d['is_vampire'] = $new;
    NsfwNpcData::save($actorName, $d);
    error_log("[AIAGENTNSFW] Recorded is_vampire=" . ($new ? 'true' : 'false') . " for {$actorName} (Papyrus race-keyword report)");
}
}

// Drunk stage from the actor's own Drink/Consume/Toast actions within a GAME-TIME window.
// Skooma/sap are excluded so hard drugs do not double-count as alcohol. Sleeping /
// waiting / fast-travel correctly sobers her. 1 game hour = 1/0.0000024 gamets
// (per comm.php's day math: 30,000,000 gamets = 72h). The OLD window had a /24 bug making it ~10 game
// MINUTES (drinks expired in seconds) - the real "never gets drunk" cause. gamets <= now guards
// save-reload "future" drinks; older drinks age out (the window IS the decay).
// Chosen affection/intimacy toward the player is stronger romance evidence than dialogue: these
// actions only fire through the consent/autonomy gates, so acting means meaning it. Flips a
// non-romantic type to 'crush' at fond+ affinity. Slaves/prostitutes excluded (coerced or
// transactional), locked relationship cards respected. UI toggle: INSTANT_CRUSH_ON_AFFECTION.
function aiagentNsfwInstantCrush($actorName, $trigger, $rawTarget = '') {
    try {
        if (!_getNsfwSetting('INSTANT_CRUSH_ON_AFFECTION', true)) { return; }
        $actorName = trim((string)$actorName);
        if ($actorName === '' || nsfwIsNarratorName($actorName)) { return; }
        $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? ''));
        // Target may arrive as the raw funcall payload; a named non-player target means this
        // affection is aimed at another NPC and says nothing about the player.
        $target = (string)$rawTarget;
        if (strpos($target, '@') !== false) { $target = explode('@', $target)[2] ?? ''; }
        $target = trim($target);
        if ($target !== '' && strcasecmp($target, 'player') !== 0
            && ($playerName === '' || stripos($target, $playerName) === false)) { return; }

        require_once __DIR__ . "/nsfw_data.php";
        $nsfw = NsfwNpcData::get($actorName);
        if (!empty($nsfw['is_slave']) || !empty($nsfw['is_prostitute'])) { return; }

        if (!class_exists('RelationshipManager')) { require_once $GLOBALS['ENGINE_PATH'] . 'lib/relationship_manager.php'; }
        $row = RelationshipManager::resolveNpcByName($actorName);
        if (!$row) { return; }
        $ext = json_decode($row['extended_data'] ?? '{}', true) ?: [];
        if (!empty($ext['relationships_locked'])) { return; }
        $rels = RelationshipManager::normalizeRelationshipMap($ext['relationships'] ?? []);
        $aff = (int)($rels['Player']['aff'] ?? 0);
        if ($aff < 56) { return; } // same fond floor as the eval-path romance gate
        // ORIENTATION (user directive 2026-07-05): a crush only forms if the NPC's orientation allows the
        // player's gender. Fails open (unknown/bi/pan -> allowed); only explicit mismatch/asexual blocks.
        if (function_exists('aiagentNsfwOrientationAllowsPlayer') && !aiagentNsfwOrientationAllowsPlayer($actorName)) {
            error_log("[AIAGENTNSFW] Instant crush blocked - orientation does not allow the player for {$actorName}");
            return;
        }
        $type = aiagentNsfwCanonicalRelationshipType($rels['Player']['type'] ?? 'neutral');
        if (in_array($type, ['romantic', 'crush', 'admirer', 'obsessed', 'infatuated', 'lover'], true)) { return; }

        RelationshipManager::setRelationship($actorName, 'Player', $aff, 'crush');
        error_log("[AIAGENTNSFW] Instant crush: {$actorName} -> Player via {$trigger} (aff {$aff}, was '{$type}')");
    } catch (Exception $e) {
        error_log("[AIAGENTNSFW] Instant crush check failed for {$actorName}: " . $e->getMessage());
    }
}

function getDrunkStageForActor($actorName) {
    if (!isset($GLOBALS["db"]) || !$GLOBALS["db"]) return 0;
    $currentGamets = (float)($GLOBALS["gameRequest"][2] ?? 0);
    if ($currentGamets <= 0) return 0;
    $windowHours = (float)(function_exists('_getNsfwSetting') ? _getNsfwSetting('DRUNK_WINDOW_HOURS', 12) : 12);
    if ($windowHours <= 0) $windowHours = 12;
    $minGamets = $currentGamets - ($windowHours / 0.0000024); // game-hours -> gamets
    $actorE = $GLOBALS["db"]->escape($actorName);
    $hardDrugRegex = $GLOBALS["db"]->escape(aiagentNsfwHardDrugRegex());
    // Consume-only mode (default): a drink counts only via the inventory-backed Consume path -
    // a real item is used and the drink animation plays. Drink/Toast are then social flavor,
    // so the model cannot intoxicate anyone by narrating drinks that never happened.
    // Consume must name an actual ALCOHOL item: without the filter, feeding an NPC bread
    // counted as a drink and kept "sober her up with food" NPCs drunk forever.
    $alcoholRegex = $GLOBALS["db"]->escape('(\male\M|mead|wine|sujamma|shein|matze|\mflin\M|brandy|bloodwine|honningbrew|black-briar|dragon.?s breath|velvet lechance|white-gold tower|cliff racer|spirits|whiskey|rum|liquor|firebrand)');
    $legacyLoose = (function_exists('_getNsfwSetting') && !_getNsfwSetting('DRUNK_REQUIRE_CONSUME_ACTION', true));
    $drinkWhere = "((action ~* '^Consume$' AND fullcall ~* '{$alcoholRegex}')"
        . ($legacyLoose ? " OR action ~* '^(Drink|Toast)$'" : "") . ")";
    $rows = $GLOBALS["db"]->fetchAll(
        "SELECT gamets FROM public.actions_issued
         WHERE actorname = '{$actorE}'
           AND gamets > {$minGamets} AND gamets <= {$currentGamets}
           AND {$drinkWhere}
           AND fullcall !~* '{$hardDrugRegex}'
         ORDER BY gamets ASC"
    );
    // Collapse actions within 10 game-minutes into ONE drink: models often chain
    // Toast+Drink (or double-call) on a single tavern beat, which insta-drunked NPCs.
    $count = 0;
    $lastCounted = -INF;
    $minSpacing = (10.0 / 60.0) / 0.0000024; // 10 game-minutes in gamets
    foreach ($rows as $r) {
        $g = (float)$r['gamets'];
        if (($g - $lastCounted) >= $minSpacing) { $count++; $lastCounted = $g; }
    }
    if ($count <= 0) return 0;
    if ($count <= 7) return $count; // 1-7 drinks -> stages 1-7 (gradual climb)
    if ($count <= 9) return 8;      // 8-9 -> 8 (wasted)
    if ($count <= 11) return 9;     // 10-11 -> 9 (near blackout)
    return 10;                      // 12+ -> 10 (blackout)
}

// SKOOMA addiction state machine (per-NPC, game-time). The addiction loop needs MEMORY (a re-dose at the crash
// returns to L2, NOT L1), so skooma cannot use a plain dose-count. States: sober/L1/L2/L3.
//   sober + bottle -> L1 (euphoric honeymoon; L1 alone wears off -> sober, NO addiction).
//   L1 + bottle -> L2.  POTENT brands (Redwater Skooma / Balmora Blue) -> straight to L2 (skip the honeymoon).
//   L2 DECAYS to L3 on a timer (so she must keep drinking to HOLD L2 = the treadmill); L2 + bottle resets the decay.
//   L3 + bottle -> L2 (NEVER L1 again - only a full detox unlocks L1).  L3 rides a long detox -> sober.
//   Skooma NEVER passes out. Computed ONCE per request (static cache), persisted to nsfw_npc_data.
function getSkoomaState($actorName) {
    static $cache = [];
    if (array_key_exists($actorName, $cache)) return $cache[$actorName];
    if (!isset($GLOBALS["db"]) || !$GLOBALS["db"]) return ($cache[$actorName] = 0);
    $now = (float)($GLOBALS["gameRequest"][2] ?? 0);
    if ($now <= 0) return ($cache[$actorName] = 0);
    require_once __DIR__ . "/nsfw_data.php";

    $data = NsfwNpcData::get($actorName);
    if (!is_array($data)) { $data = []; }
    $gph = 1 / 0.0000024; // gamets per game-hour

    $state    = $data["skooma_state"] ?? 'sober';
    $stateAt  = (float)($data["skooma_state_gamets"] ?? 0);
    $lastDose = isset($data["skooma_last_dose"]) ? (float)$data["skooma_last_dose"] : ($now - 0.5 * $gph); // cold start: only recent doses
    $changed = false;
    // Dragon Break: reloading a save BEFORE the dose(s) that built this state means, in the
    // restored timeline, those doses never happened - so the addiction must REVERT to sober, not
    // just rebase its timer. The old clamp kept her at L3 and restarted the detox clock from the
    // reload point, so she stayed permanently high after a rollback (the reported bug). Reverting
    // here also lets context.php drive the OAR var / SpeedMult back to 0. The postrequest /
    // context_pre reconciler handles subtler partial rollbacks; this keeps getSkoomaState honest on
    // the very first post-reload request (its static cache would otherwise echo the stale level all turn).
    if ($stateAt > $now || $lastDose > $now) {
        $state    = 'sober';
        $stateAt  = 0;
        $lastDose = $now - 0.5 * $gph; // cold-start: only doses in the last half game-hour rebuild state
        $changed  = true;
        error_log("[AIAGENTNSFW] Skooma Dragon Break for {$actorName} (save reloaded before dose) -> sober");
    }

    $l1Wear  = (float)(function_exists('_getNsfwSetting') ? _getNsfwSetting('SKOOMA_L1_WEAROFF_HOURS', 6)  : 6);
    $l2Decay = (float)(function_exists('_getNsfwSetting') ? _getNsfwSetting('SKOOMA_L2_DECAY_HOURS', 3)     : 3);
    $l3Detox = (float)(function_exists('_getNsfwSetting') ? _getNsfwSetting('SKOOMA_L3_DETOX_HOURS', 24)    : 24);

    // New skooma doses since last processed (regular + Balmora Blue / Redwater), oldest first.
    // Default is Consume-only so skooma requires the inventory-backed DrinkPotion path, not generic drink flavor.
    $aE = $GLOBALS["db"]->escape($actorName);
    $minG = max($lastDose, $now - 0.5 * $gph);
    $skoomaRegex = $GLOBALS["db"]->escape(aiagentNsfwSkoomaRegex());
    $doseActionRegex = $GLOBALS["db"]->escape(aiagentNsfwDrugDoseActionRegex());
    $rows = $GLOBALS["db"]->fetchAll(
        "SELECT gamets, fullcall FROM public.actions_issued
         WHERE actorname = '{$aE}' AND action ~* '{$doseActionRegex}'
           AND gamets > {$minG} AND gamets <= {$now}
           AND fullcall ~* '{$skoomaRegex}'
         ORDER BY gamets ASC"
    );
    foreach ($rows as $r) {
        $potent = (preg_match('/(redwater|balmora blue)/i', (string)($r['fullcall'] ?? '')) === 1);
        if ($potent)               { $state = 'L2'; }   // too potent for the honeymoon - straight to peak
        elseif ($state === 'sober') { $state = 'L1'; }
        elseif ($state === 'L1')    { $state = 'L2'; }
        elseif ($state === 'L3')    { $state = 'L2'; }   // re-dose while crashed - back to L2, never L1
        // ($state === 'L2' just holds L2 and resets the decay timer below = the treadmill)
        $stateAt  = (float)$r['gamets'];
        $lastDose = (float)$r['gamets'];
        $changed  = true;
    }

    // Time-driven transitions (only when no dose reset the timer this turn).
    if (!$changed && $stateAt > 0) {
        $elapsedH = ($now - $stateAt) * 0.0000024;
        if ($state === 'L1' && $elapsedH >= $l1Wear)      { $state = 'sober'; $stateAt = $now; $changed = true; }
        elseif ($state === 'L2' && $elapsedH >= $l2Decay) { $state = 'L3';    $stateAt = $now; $changed = true; }
        elseif ($state === 'L3' && $elapsedH >= $l3Detox) { $state = 'sober'; $stateAt = $now; $changed = true; }
    }

    if ($changed) {
        $data["skooma_state"]        = $state;
        $data["skooma_state_gamets"] = $stateAt;
        $data["skooma_last_dose"]    = $lastDose;
        NsfwNpcData::save($actorName, $data);
        error_log("[AIAGENTNSFW] Skooma state for {$actorName} -> {$state}");
    }

    $map = ['sober' => 0, 'L1' => 1, 'L2' => 2, 'L3' => 3];
    return ($cache[$actorName] = ($map[$state] ?? 0));
}

// Drug level dispatch. Skooma uses the addiction state machine above; sleeping tree sap is a simple one-hit
// (any dose in the game-time window -> level 1 paralysis). Drugs default to the LLM 'Consume' action because
// core backs it with inventory lookup + DrinkPotion.
function getDrugStageForActor($actorName, $substance) {
    if (!isset($GLOBALS["db"]) || !$GLOBALS["db"]) return 0;
    if ($substance === 'skooma') return getSkoomaState($actorName);

    $currentGamets = (float)($GLOBALS["gameRequest"][2] ?? 0);
    if ($currentGamets <= 0) return 0;
    $windowHours = (float)(function_exists('_getNsfwSetting') ? _getNsfwSetting('DRUG_WINDOW_HOURS', 6) : 6);
    if ($windowHours <= 0) $windowHours = 6;
    $minGamets = $currentGamets - ($windowHours / 0.0000024);
    $actorE = $GLOBALS["db"]->escape($actorName);
    $doseActionRegex = $GLOBALS["db"]->escape(aiagentNsfwDrugDoseActionRegex());
    $win = "actorname = '{$actorE}' AND gamets > {$minGamets} AND gamets <= {$currentGamets} AND action ~* '{$doseActionRegex}'";

    if ($substance === 'sleeping_tree_sap') {
        $sapRegex = $GLOBALS["db"]->escape(aiagentNsfwSapRegex());
        $row = $GLOBALS["db"]->fetchOne(
            "SELECT count(*) AS c FROM public.actions_issued
             WHERE {$win} AND fullcall ~* '{$sapRegex}'"
        );
        return ((int)($row['c'] ?? 0) > 0) ? 1 : 0; // one-hit
    }

    return 0;
}

// The Narrator is a pseudo-speaker, never a real character: it must NEVER enter scene/relationship/parentage state.
// Name-robust because functions.php renames "The Narrator" -> "Character" mid-request.
function nsfwIsNarratorName($name) {
    $n = strtolower(trim((string)$name));
    return $n === 'the narrator' || $n === 'character' || $n === 'narrator';
}

function getGlobalPrompt($promptKey) {
    static $promptsCache = null;
    if ($promptsCache === null) {
        try {
            $settingsRow = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_prompts'");
            if ($settingsRow && !empty($settingsRow['value'])) {
                $promptsCache = json_decode($settingsRow['value'], true) ?: [];
            } else {
                $promptsCache = [];
            }
        } catch (Exception $e) {
            $promptsCache = [];
        }
    }
    return $promptsCache[$promptKey] ?? '';
}

// A prostitute waives payment (gives herself FREE) when her current affinity tier is set to 100% off
// (-100) in the Affinity Price Modifiers grid. ONE lever: the grid. When waived, every unpaid-prostitute
// gate (refusal / nonpayment force-exit guard / "get paid" context / action lock) is skipped so she gives
// herself freely and is never kicked out of the scene. Set the tier below 100% to make her charge again.
function aiagentNsfwProstitutePaymentWaived($npcName) {
    if (!function_exists('isProstitute') || !isProstitute($npcName)) {
        return false;
    }
    $aff = function_exists('getNpcAffinity') ? (int)getNpcAffinity($npcName) : 0;
    return aiagentNsfwProstituteTierModifierPct($aff) <= -100;
}

// === Durable prostitute payment ledger ============================================
// payment_confirmed lives inside the big intimacy JSON blob, which is persisted with
// array_merge(freshFromDB, staleInMemoryCopy) in updateIntimacyForActor(). A handler that
// loaded intimacy BEFORE a payment confirmed will write its stale copy back afterwards and
// silently reset payment_confirmed to false (observed: confirm at T, wiped at T+13ms, scene
// then refuses a paid client). To make a confirmed payment survive, we mirror it into a
// SIBLING key on the same nsfw_npc_data row (aiagent_nsfw_payment_ledger) that the intimacy
// writers never touch, then heal payment_confirmed from it on read. Consumed on player
// orgasm or after the window (PROSTITUTE_PAYMENT_WINDOW_MINUTES, default 20; 0 = never expires).
function aiagentNsfwPaymentWindowSeconds() {
    $mins = function_exists('_getNsfwSetting') ? (int)_getNsfwSetting('PROSTITUTE_PAYMENT_WINDOW_MINUTES', 20) : 20;
    if ($mins < 0) { $mins = 0; }
    return $mins * 60;
}

function aiagentNsfwSetPaymentLedger($npcName, $amount, $price, $gold = 0) {
    if ($npcName === '' || $npcName === ($GLOBALS["PLAYER_NAME"] ?? null)) { return; }
    $extended = NsfwNpcData::get($npcName); // fresh read -> tiny clobber window
    $extended['aiagent_nsfw_payment_ledger'] = [
        'paid'   => true,
        'amount' => (int)$amount,
        'price'  => (int)$price,
        'gold'   => (int)$gold,
        'time'   => time(),
    ];
    NsfwNpcData::save($npcName, $extended);
    error_log("[AIAGENTNSFW] Payment ledger SET for {$npcName}: amount={$amount} price={$price}");
}

function aiagentNsfwClearPaymentLedger($npcName, $reason = '') {
    if ($npcName === '') { return; }
    $extended = NsfwNpcData::get($npcName);
    if (isset($extended['aiagent_nsfw_payment_ledger'])) {
        unset($extended['aiagent_nsfw_payment_ledger']);
        NsfwNpcData::save($npcName, $extended);
        error_log("[AIAGENTNSFW] Payment ledger CLEARED for {$npcName}" . ($reason !== '' ? " ({$reason})" : ""));
    }
}

// True if this NPC has an active (unexpired) confirmed payment. Clears stale ledgers as a side effect.
// Pass $ledger to reuse an already-loaded row and avoid an extra DB read.
function aiagentNsfwPaymentLedgerActive($npcName, $ledger = null) {
    if ($npcName === '') { return false; }
    if ($ledger === null) {
        $extended = NsfwNpcData::get($npcName);
        $ledger = $extended['aiagent_nsfw_payment_ledger'] ?? null;
    }
    if (!is_array($ledger) || empty($ledger['paid'])) { return false; }
    $window = aiagentNsfwPaymentWindowSeconds();
    if ($window > 0) {
        $age = time() - (int)($ledger['time'] ?? 0);
        if ($age > $window) {
            aiagentNsfwClearPaymentLedger($npcName, "expired after {$window}s window");
            return false;
        }
    }
    return true;
}

function isNpcSlave($actorName) {
    // Get from nsfw_npc_data table (NOT core_npc_master.extended_data)
    $extended = NsfwNpcData::get($actorName);
    return !empty($extended['is_slave']);
}

function setNpcSlaveStatus($actorName, $isSlave) {
    // Get from nsfw_npc_data table (NOT core_npc_master.extended_data)
    $extended = NsfwNpcData::get($actorName);

    if ($isSlave) {
        $extended['is_slave'] = true;
    } else {
        unset($extended['is_slave']);
    }

    // Save to nsfw_npc_data table
    NsfwNpcData::save($actorName, $extended);
    return true;
}

function freeNpcSlave($actorName) {
    return setNpcSlaveStatus($actorName, false);
}

/**
 * Build negotiation context for prostitute at tier_prompt phase
 * Uses the full prostitute configuration from is_prostitute checkbox:
 * - prostitute_type: streetwalker, courtesan, escort, tavern_worker, temple_prostitute, camp_follower
 * - prostitute_pricing: individual_acts, time_bookings, style_addons, group_premiums
 * - payment_type: gold, favors, goods, mixed
 * - motivation: professional, desperate, etc.
 * - personality_prompt, during_prompt, after_prompt
 *
 * @param string $npcName The prostitute NPC
 * @param string $clientName The client
 * @param int $affinity Affinity score with client
 * @return string Context to inject into personality
 */
function buildProstituteNegotiationContext($npcName, $clientName, $affinity) {
    $context = "";

    // Get NPC's NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
    $extended = NsfwNpcData::get($npcName);

    // Get tier name from affinity
    $tierName = getAffinityTierName($affinity);

    // === PROSTITUTE TYPE ===
    // Types: streetwalker, courtesan, escort, tavern_worker, temple_prostitute, camp_follower
    // Check top-level first, then inside pricing (for backwards compatibility)
    $pricing = $extended['prostitute_pricing'] ?? null;
    $prostituteType = $extended['prostitute_type']
        ?? ($pricing['prostitute_type'] ?? null)
        ?? 'streetwalker';
    $typeDescriptions = [
        'streetwalker' => 'You work the streets. Quick transactions, straightforward pricing.',
        'courtesan' => 'You are a refined courtesan. You offer companionship and intimacy to those who can afford luxury.',
        'escort' => 'You are a professional escort. Discretion and quality service are your hallmarks.',
        'tavern_worker' => 'You work at a tavern. You entertain patrons who catch your eye or pay well enough.',
        'temple_prostitute' => 'You serve at a temple. Your services are considered sacred rites.',
        'camp_follower' => 'You follow armies and camps. You provide comfort to soldiers and travelers.'
    ];
    $typeDesc = $typeDescriptions[$prostituteType] ?? $typeDescriptions['streetwalker'];

    // === PAYMENT & MOTIVATION ===
    // Payment type preference: gold, favors, goods, mixed
    $paymentType = 'gold';
    $motivation = 'professional';
    if ($pricing && is_array($pricing)) {
        $paymentType = $pricing['payment_type'] ?? 'gold';
        $motivation = $pricing['motivation'] ?? 'professional';
    }

    // Payment type descriptions
    $paymentDescriptions = [
        'gold' => 'You only accept gold payment.',
        'favors' => 'You accept favors and services in exchange. Build trust first.',
        'goods' => 'You accept valuable goods and items as payment.',
        'mixed' => 'You accept gold, goods, or favors - whatever works.'
    ];
    $paymentDesc = $paymentDescriptions[$paymentType] ?? $paymentDescriptions['gold'];

    // Motivation affects behavior
    $motivationDescriptions = [
        'professional' => 'This is your profession. Business-like and efficient.',
        'desperate' => 'You need the money badly. More willing to negotiate down.',
        'luxurious' => 'You cater to wealthy clients. Premium prices, premium service.',
        'survival' => 'You do this to survive. Pragmatic about it.',
        'enjoyment' => 'You genuinely enjoy your work. Enthusiastic service.'
    ];
    $motivationDesc = $motivationDescriptions[$motivation] ?? '';

    // === NPC'S CUSTOM PERSONALITY PROMPT ===
    $personalityPrompt = '';
    if ($pricing && !empty($pricing['personality_prompt'])) {
        $personalityPrompt = $pricing['personality_prompt'];
        // Replace placeholders
        $personalityPrompt = str_replace('#NPC_NAME#', $npcName, $personalityPrompt);
        $personalityPrompt = str_replace('#PLAYER_NAME#', $clientName, $personalityPrompt);
    }

    // === BUILD FULL CONTEXT ===
    $context = "\n#NEGOTIATION PHASE";
    $context .= "\nYou accepted {$clientName} as a potential client.";
    $context .= "\n#Type: " . ucfirst($prostituteType) . " - " . $typeDesc;

    if (!empty($motivationDesc)) {
        $context .= "\n#Motivation: " . $motivationDesc;
    }

    if (!empty($personalityPrompt)) {
        $context .= "\n#Your Style: " . $personalityPrompt;
    }

    // ONE flat base price for the whole scene (migrate from old menu if prostitute_price unset)
    $flatPrice = (int)($extended['prostitute_price'] ?? 0);
    if ($flatPrice < 1 && is_array($pricing) && !empty($pricing['individual_acts'])) {
        $acts = $pricing['individual_acts'];
        $flatPrice = (int)($acts['full_both'] ?? $acts['full_vaginal'] ?? (reset($acts) ?: 0));
    }
    if ($flatPrice < 1) $flatPrice = 100;

    // Apply the affinity-tier discount/premium so the quoted price matches the payment gate + overhead label.
    $finalPrice = aiagentNsfwProstituteAffinityPrice($flatPrice, $affinity);
    if ($finalPrice < 1) $finalPrice = 1;

    // Negotiation guidance is UI-editable (Prompts tab -> Prostitution (Global) -> Negotiation Instructions).
    // #PRICE# = affinity-adjusted price, #PLAYER_NAME# = the client. Fallbacks match the shipped defaults.
    $fillNeg = function($tmpl) use ($finalPrice, $clientName) {
        return str_replace(['#PRICE#', '#PLAYER_NAME#'], [$finalPrice, $clientName], $tmpl);
    };
    $waived = function_exists('aiagentNsfwProstitutePaymentWaived') && aiagentNsfwProstitutePaymentWaived($npcName);
    if ($waived) {
        // Affinity high enough that she gives herself freely (auto-waived): never charge.
        $tmpl = getGlobalPrompt('prostitute_negotiation_waived') ?: "You care for #PLAYER_NAME# far too much to take their coin. Do NOT quote a price or ask for gold - give yourself to them freely and begin the act (MakeLove / the matching action). This is love, not work.";
        $context .= "\n#" . $fillNeg($tmpl);
    } else {
        $tmpl = getGlobalPrompt('prostitute_negotiation_charge') ?: "Your price: #PRICE# gold for the whole scene - ONE flat rate, agreed up front, fixed start to finish; do NOT itemize or charge per act. Tell #PLAYER_NAME# your price (#PRICE# gold); use AcceptSex to agree or RefuseSex to decline. Once they agree, use TakeGold with #PRICE# to take payment, then MakeLove to begin. The price stays fixed for the entire scene.";
        $context .= "\n#" . $fillNeg($tmpl);
        if ((int)$affinity >= 76) {
            // Devoted+: GiveFreeService is unlocked for her. Make the free option EXPLICIT so she doesn't
            // default to demanding payment. She must pick a path - charge, or deliberately waive via the action.
            $tmpl = getGlobalPrompt('prostitute_negotiation_free_choice') ?: "You have real feelings for #PLAYER_NAME#, so this time you have a CHOICE: either charge your price as above, OR give this service for free. If you choose to waive payment, call the GiveFreeService action (do NOT take any gold) and then begin. Decide in character based on how much you care - do not just silently skip payment, pick ONE of the two paths.";
            $context .= "\n\n#" . $fillNeg($tmpl);
        }
    }

    error_log("[AIAGENTNSFW] Built negotiation context for {$npcName} (type: {$prostituteType}, payment: {$paymentType}, tier: {$tierName}, waived: " . ($waived ? 'yes' : 'no') . ")");

    return $context;
}

// Per-NPC prostitute scene prompt set under the is_prostitute checkbox: 'personality_prompt' (negotiation),
// 'during_prompt' (in-scene behavior), 'after_prompt' (post-service/pillow talk). Returns '' if unset.
// Substitutes #NPC_NAME# / #PLAYER_NAME#. These are the prostitute's OWN service code - used instead of the
// generic sex_speech_style during a paid scene.
function aiagentNsfwGetProstituteScenePrompt($npcName, $key) {
    if ($npcName === '' || $key === '') { return ''; }
    $extended = NsfwNpcData::get($npcName);
    $pricing = $extended['prostitute_pricing'] ?? null;
    if (!is_array($pricing) || empty($pricing[$key])) { return ''; }
    $client = $GLOBALS['PLAYER_NAME'] ?? 'the client';
    return str_replace(['#NPC_NAME#', '#PLAYER_NAME#'], [$npcName, $client], (string)$pricing[$key]);
}

/**
 * Get global NSFW prompts from database
 */
function getGlobalNsfwPrompts() {
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $row = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_prompts'");
        if ($row && !empty($row['value'])) {
            $cache = json_decode($row['value'], true) ?: [];
        } else {
            $cache = [];
        }
    } catch (Exception $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * Get affinity tier name from affinity score
 */
function getAffinityTierName($affinity) {
    if ($affinity >= 91) return 'bonded';
    if ($affinity >= 76) return 'devoted';
    if ($affinity >= 56) return 'fond';
    if ($affinity >= 31) return 'friendly';
    if ($affinity >= 6) return 'acquaintance';
    if ($affinity >= -5) return 'neutral';
    if ($affinity >= -30) return 'wary';
    if ($affinity >= -55) return 'cold';
    if ($affinity >= -75) return 'resentful';
    if ($affinity >= -90) return 'hateful';
    return 'hostile';
}

/**
 * Per-tier prostitute price discount/premium schedule (single source of truth).
 * Value = percent CHANGE to her flat rate: negative = discount (she charges less at higher affinity),
 * positive = surcharge (she charges more at lower affinity). Configurable in the UI under
 * Global Prostitutes (PROSTITUTE_PRICE_MODIFIERS); these are the gentle defaults.
 */
function aiagentNsfwProstitutePriceModifierDefaults() {
    return [
        'bonded'       => -100,  // 100% off = free (she gives herself freely; bypasses the nonpayment guard)
        'devoted'      => -100,  // 100% off = free
        'fond'         => -20,
        'friendly'     => -10,
        'acquaintance' => 0,
        'neutral'      => 0,
        'wary'         => 10,
        'cold'         => 25,
        'resentful'    => 50,
        'hateful'      => 100,
        'hostile'      => 200,
    ];
}

/**
 * The price-modifier % for a prostitute's affinity tier (single source of truth = the grid).
 * Negative = discount, positive = surcharge, -100 (or beyond) = 100% off = FREE.
 * Stored in the prompts store under Prompts -> Prostitution (Global) -> Affinity Price Modifiers.
 */
function aiagentNsfwProstituteTierModifierPct($affinity) {
    $tier = getAffinityTierName((int)$affinity);
    $defaults = aiagentNsfwProstitutePriceModifierDefaults();
    $mods = function_exists('getGlobalPrompt') ? getGlobalPrompt('prostitute_price_modifiers') : '';
    $mods = (is_array($mods) && !empty($mods)) ? array_merge($defaults, $mods) : $defaults;
    return array_key_exists($tier, $mods) ? (float)$mods[$tier] : 0.0;
}

/**
 * Apply the affinity-tier price modifier to a prostitute's flat base price. Used by every path that
 * quotes or verifies the price (negotiation context, payment gate, overhead #PRICE# label) so the
 * number the model states, the amount the gate expects, and the label all MATCH.
 * A tier set to 100% off (-100) returns 0 = genuinely free (and aiagentNsfwProstitutePaymentWaived
 * treats that same tier as waived, so the nonpayment guard is bypassed).
 */
function aiagentNsfwProstituteAffinityPrice($basePrice, $affinity) {
    $base = (int)$basePrice;
    if ($base < 1) { return $base; }
    $pct = aiagentNsfwProstituteTierModifierPct($affinity);
    if ($pct <= -100) { return 0; } // 100% discount = free
    $price = (int)round($base * (1 + $pct / 100));
    return max(1, $price);
}

/**
 * Load NSFW general settings from database (conf_opts 'aiagent_nsfw_settings')
 * Used by preprocessing, prompts, prerequest — lives in common.php so it's
 * always available without loading the heavy prompts.php file.
 */
function _getNsfwSetting($key, $default = null) {
    // (The global slut-mode floor interception that lived here was removed 2026-07-10 with the
    // global switch; per-NPC promiscuous floors go through aiagentNsfwSceneCallFloorFor.)
    static $cache = null;
    static $dbWasAvailable = false;

    // If cache was initialized before db was available, re-init now that db exists
    if ($cache !== null && !$dbWasAvailable && isset($GLOBALS["db"])) {
        $cache = null;
    }

    if ($cache === null) {
        $cache = [];
        $dbWasAvailable = isset($GLOBALS["db"]);
        if ($dbWasAvailable) {
            try {
                $row = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_settings'");
                if ($row && !empty($row['value'])) {
                    $cache = json_decode($row['value'], true) ?: [];
                }
            } catch (Exception $e) {
                // Silently fail, use defaults
            }
        }
    }

    return isset($cache[$key]) ? $cache[$key] : $default;
}


function aiagentNsfwSettingBool($key, $default = true) {
    $v = _getNsfwSetting($key, $default);
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v) || is_float($v)) {
        return ((int)$v) !== 0;
    }
    if (is_string($v)) {
        $s = strtolower(trim($v));
        if (in_array($s, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
    }
    return (bool)$default;
}

function aiagentNsfwDetectOStimScenes() {
    return aiagentNsfwSettingBool('DETECT_OSTIM_SCENES', true);
}

function aiagentNsfwDetectSexLabScenes() {
    return aiagentNsfwSettingBool('DETECT_SEXLAB_SCENES', true);
}

function aiagentNsfwPayloadFramework($data) {
    if (!is_string($data) || $data === '') {
        return '';
    }
    if (preg_match('/(?:^|[\\/^])fw=(ostim|sexlab)(?:$|[\\/^])/i', $data, $m)) {
        return strtolower($m[1]);
    }
    return '';
}

function aiagentNsfwSceneFrameworkAllowed($event, $data) {
    $fw = aiagentNsfwPayloadFramework($data);
    if ($fw === 'ostim' && !aiagentNsfwDetectOStimScenes()) {
        return false;
    }
    if ($fw === 'sexlab' && !aiagentNsfwDetectSexLabScenes()) {
        return false;
    }
    return true;
}

// Numeric setting with a safe fallback - arousal gains/thresholds are UI-tunable numbers.
function aiagentNsfwArousalNum($key, $default) {
    $v = _getNsfwSetting($key, $default);
    return is_numeric($v) ? (float)$v : (float)$default;
}

// May NPC A START a sex scene with NPC B? (user directive 2026-07-05: allow NPC<->NPC scenes if affinity +
// rel type are high enough). Prostitute A bypasses (services anyone). Slave A is PLAYER-ONLY (cannot initiate
// NPC<->NPC). Otherwise A->B must clear the SAME standard as the player gate: affinity >= scene-call floor AND
// A's rel TYPE toward B is one of the UI-checked eligible types (unless the rel-type gate is disabled in UI).
// Fails CLOSED for a normal NPC pair with no qualifying bond (strangers default to neutral/0). This is the
// OUTBOUND-initiation gate only; it does NOT revive the removed inbound isNpcAffinityGatingEnabled toggle.
function aiagentNsfwNpcToNpcSexEligible($npcA, $npcB) {
    $npcA = trim((string)$npcA); $npcB = trim((string)$npcB);
    if ($npcA === '' || $npcB === '' || strcasecmp($npcA, $npcB) === 0) { return false; }
    // Hard protection runs before open-mode or special-lane bypasses.
    if (function_exists('aiagentNsfwIsProtectedNpc') && (aiagentNsfwIsProtectedNpc($npcA) || aiagentNsfwIsProtectedNpc($npcB))) { return false; }
    // OPEN MODE: any adult NPC may engage any adult NPC (incl. slaves); children never.
    if (aiagentNsfwOpenMode()) {
        return true;
    }
    if (function_exists('isNpcSlave') && isNpcSlave($npcA)) { return false; }        // slave A: player-only
    if (function_exists('isProstitute') && isProstitute($npcA)) { return true; }     // prostitute A: anyone
    if (!class_exists('RelationshipManager')) { return false; }
    $rel = null;
    try { $rel = RelationshipManager::getRelationship($npcA, $npcB); } catch (Exception $e) { return false; }
    if (!is_array($rel)) { return false; }
    $aff  = (int)($rel['aff'] ?? 0);
    $type = aiagentNsfwCanonicalRelationshipType($rel['type'] ?? '');
    // Promiscuous-marked initiator: NPC-to-NPC needs only Acquaintance affinity between the pair;
    // the type check is skipped. (Outbound gate = A's own standard for starting with B.)
    if (aiagentNsfwIsSlutNpc($npcA)) { return $aff >= 6; }
    $floor = (int)_getNsfwSetting('NSFW_SCENE_CALL_MIN_AFFINITY', 56);
    if ($aff < $floor) { return false; }
    $eligible = null;
    try {
        $row = (isset($GLOBALS["db"]) && $GLOBALS["db"]) ? $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_reltypes'") : null;
        if ($row && !empty($row['value'])) {
            $cfg = json_decode($row['value'], true);
            if (is_array($cfg)) {
                if (array_key_exists('enabled', $cfg) && !$cfg['enabled']) { return true; }
                if (is_array($cfg['eligible_types'] ?? null)) { $eligible = array_map('strtolower', $cfg['eligible_types']); }
            }
        }
    } catch (Exception $e) { return true; }
    if (empty($eligible)) { return true; }
    return in_array($type, $eligible, true);
}

// Does this NPC's sexual orientation ALLOW attraction to the player's gender? (user directive 2026-07-05)
// Gates tier-1/2 affection tools and crush formation. FAILS OPEN: returns true for match / bisexual /
// pansexual / unknown-orientation / unknown-gender / any error - only an EXPLICIT mismatch (straight NPC +
// same-gender player, or gay NPC + opposite-gender player) or an ASEXUAL NPC returns false. Mirrors the
// flavor-only NsfwRelationship::checkOrientationMatch logic, promoted here to a real gate.
function aiagentNsfwOrientationAllowsPlayer($npcName) {
    // OPEN MODE: orientation never walls off engagement (it stays personality flavor in the profile).
    if (aiagentNsfwOpenMode()) { return true; }
    try {
        require_once __DIR__ . "/nsfw_data.php";
        $ext = NsfwNpcData::get($npcName);
        $orientation = strtolower(trim((string)($ext['sexual_orientation'] ?? '')));
        if ($orientation === '' || $orientation === 'bisexual' || $orientation === 'pansexual') { return true; }
        if ($orientation === 'asexual') { return false; }
        // Player gender
        $playerGender = 'male';
        if (isset($GLOBALS['PLAYER_SEX'])) { $playerGender = ((int)$GLOBALS['PLAYER_SEX'] === 1) ? 'female' : 'male'; }
        elseif (isset($GLOBALS['PLAYER_GENDER'])) { $playerGender = strtolower((string)$GLOBALS['PLAYER_GENDER']); }
        // NPC gender: core_npc_master.gender preferred, then nsfw profile sex
        $npcGender = '';
        if (class_exists('NpcMaster')) {
            $nd = (new NpcMaster())->getByName($npcName);
            if (!empty($nd['gender'])) { $npcGender = strtolower((string)$nd['gender']); }
        }
        if ($npcGender === '' && isset($ext['sex'])) {
            $npcGender = ((int)$ext['sex'] === 1 || strtolower((string)$ext['sex']) === 'female') ? 'female' : 'male';
        }
        if ($npcGender === '') { return true; } // unknown gender -> do not block
        if ($orientation === 'heterosexual' || $orientation === 'straight') { return $npcGender !== $playerGender; }
        if ($orientation === 'homosexual' || $orientation === 'gay' || $orientation === 'lesbian') { return $npcGender === $playerGender; }
        return true; // unknown orientation label -> do not block
    } catch (Throwable $t) {
        return true; // never block affection on an error
    }
}

// CONFLICT DETECTION: live combat around the PLAYER right now? The newest beings-in-range report
// carries game-stamped state suffixes ("(in combat)" / "(hostile)"). Freshness-gated by wall clock
// so a stale row from a previous play session can never block affection.
function aiagentNsfwPlayerConflictActive() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = false;
    if (empty($GLOBALS["db"]) || !_getNsfwSetting('NSFW_COMBAT_BLOCK_ENABLED', true)) return $cached;
    $win = (int)_getNsfwSetting('NSFW_COMBAT_BLOCK_WINDOW_SECONDS', 45);
    if ($win <= 0) return $cached;
    try {
        $row = $GLOBALS["db"]->fetchOne("SELECT data, localts FROM eventlog WHERE type='infonpc_close' ORDER BY rowid DESC LIMIT 1");
        if ($row && (time() - (int)($row['localts'] ?? 0)) <= $win
            && preg_match('/\((?:in combat|hostile)\)/i', (string)($row['data'] ?? ''))) {
            $cached = true;
        }
    } catch (Exception $e) {}
    return $cached;
}
