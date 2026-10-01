<?php
/**
 * NSFW Relationship Bridge
 *
 * Connects RelationshipManager to affinity-gated NSFW tier prompts.
 * Returns the appropriate tier prompt based on NPC's affinity with player.
 *
 * Tier Mapping (from RelationshipManager):
 *   Hostile (-100 to -91), Hateful (-90 to -76), Resentful (-75 to -56),
 *   Cold (-55 to -31), Wary (-30 to -6), Neutral (-5 to +5),
 *   Acquaintance (+6 to +30), Friendly (+31 to +55), Fond (+56 to +75),
 *   Devoted (+76 to +90), Bonded (+91 to +100)
 *
 * Usage:
 *   $tierPrompt = NsfwRelationship::getTierPrompt($npcName, $isProstitute);
 */

require_once __DIR__ . "/../../lib/relationship_manager.php";

class NsfwRelationship {

    // Cache for prompt settings
    private static $promptCache = null;

    /**
     * Wrap content in XML tags for LLM context separation
     * @param string $tag The XML tag name (e.g., 'relationship_context')
     * @param string $content The content to wrap
     * @return string XML-wrapped content
     */
    public static function wrapXml($tag, $content) {
        if (empty($content)) {
            return '';
        }
        return "<{$tag}>\n{$content}\n</{$tag}>";
    }

    private static function shouldUseOpenModePlayerScenePrompt($npcName, $isProstitute, $promptContext = 'player') {
        if ($isProstitute || strtolower(trim((string)$promptContext)) === 'npc') {
            return false;
        }
        $npcName = trim((string)$npcName);
        if ($npcName === ''
            || !function_exists('aiagentNsfwOpenMode')
            || !aiagentNsfwOpenMode()
            || !function_exists('aiagentNsfwIsProtectedNpc')
            || aiagentNsfwIsProtectedNpc($npcName)
            || !function_exists('aiagentNsfwBuildOpenModeScenePrompt')) {
            return false;
        }
        return true;
    }

    private static function getOpenModePlayerScenePrompt($npcName, $partnerName, $affinity, $tier) {
        return aiagentNsfwBuildOpenModeScenePrompt(
            $npcName,
            $partnerName ?: 'Player',
            (int)$affinity,
            ucfirst(strtolower((string)$tier))
        );
    }

    /**
     * Get the appropriate tier prompt for an NPC based on their relationship with the player
     *
     * @param string $npcName The NPC's name
     * @param bool $isProstitute Whether the NPC is flagged as a prostitute
     * @param string|null $partnerName Optional partner name (defaults to "Player")
     * @return string The tier prompt with placeholders replaced
     */
    public static function getTierPrompt($npcName, $isProstitute = false, $partnerName = null) {
        // Get relationship with player
        $relationship = RelationshipManager::getPlayerRelationship($npcName);
        $tier = strtolower($relationship['tier']); // e.g., "hostile", "friendly", "bonded"

        if (self::shouldUseOpenModePlayerScenePrompt($npcName, $isProstitute, 'player')) {
            return self::getOpenModePlayerScenePrompt($npcName, $partnerName ?? 'Player', $relationship['aff'] ?? 0, $tier);
        }

        // Load prompt settings
        $prompts = self::loadPromptSettings();

        // Determine which prompt set to use
        $promptKey = $isProstitute ? "tier_prost_{$tier}" : "tier_{$tier}";

        // Get the prompt from database - NO FALLBACK TO HARDCODED DEFAULTS
        // If prompt is not in DB, log error and return empty (user must save prompts in UI first)
        $prompt = $prompts[$promptKey] ?? '';
        // PROMISCUOUS MARK: is_slut NPCs swap to the tier_slut_<tier> scene set (eager Acquainted+
        // acceptance, RefuseSex gates below); null = mark absent or set blank, keep the regular path.
        $slutTierText = self::getSlutTierPromptText($npcName, $tier, $isProstitute, $prompts);
        if ($slutTierText !== null) { $prompt = $slutTierText; }
        if (empty($prompt) && !$isProstitute) {
            // Regular-NPC safety net: profileless / fresh-DB NPCs would otherwise emit EMPTY tier context
            // (NPC-NPC dialogue then silently fails). Prostitutes use their own pathway - no fallback.
            require_once __DIR__ . "/nsfw_data.php";
            $_defTiers = NsfwData::getDefaultTierPrompts();
            $prompt = $_defTiers[ucfirst($tier)]['regular'] ?? ($_defTiers['Neutral']['regular'] ?? '');
        }
        if (empty($prompt)) {
            error_log("[NSFW_REL] ERROR: No prompt found for key '{$promptKey}' - user must save prompts in NSFW Config UI");
        }

        // Player relationship route: affinity tier prompts describe the player's relationship.
        $prompt = self::replacePlayerRoutePlaceholders($prompt, $partnerName ?? 'Player', $npcName);
        $prompt = str_replace('#NPC_NAME#', $npcName, $prompt);
        $prompt = str_replace('#AFFINITY#', $relationship['aff'], $prompt);
        $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);
        $prompt = str_replace('#TYPE#', $relationship['type'], $prompt);

        return $prompt;
    }

    /**
     * Get tier prompt by affinity score directly
     * Handles: Marriage (spouse+spouse), Affair (married but not spouse), Regular (not married)
     *
     * @param int $affinity The affinity score (-100 to +100)
     * @param bool $isProstitute Whether to use prostitute prompts
     * @param string $partnerName Partner name for placeholder replacement
     * @param string|null $npcName NPC name for marriage/affair detection (optional)
     * @return string The tier prompt
     */
    public static function getTierPromptByAffinity($affinity, $isProstitute = false, $partnerName = 'Player', $npcName = null, $promptContext = 'player') {
        $tier = strtolower(RelationshipManager::getTierLabel($affinity));
        $isNpcPromptContext = (strtolower((string)$promptContext) === 'npc');

        if (self::shouldUseOpenModePlayerScenePrompt($npcName, $isProstitute, $promptContext)) {
            return self::getOpenModePlayerScenePrompt($npcName, $partnerName, $affinity, $tier);
        }

        // Check for marriage/affair scenarios if NPC name is provided
        if ($npcName) {
            // MARRIAGE: Partner IS the spouse - use marriage prompts
            if (self::isMarriageScenario($npcName, $partnerName)) {
                $prompt = $isNpcPromptContext
                    ? self::getNpcMarriageSpousePrompt($tier, $partnerName, $npcName)
                    : self::getMarriageSpousePrompt($tier, $partnerName);
                $prompt = str_replace('#AFFINITY#', $affinity, $prompt);
                $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);
                error_log("[NSFW_REL] Marriage scene detected for $npcName with spouse $partnerName (context: {$promptContext})");
                // After marriage prompt, fall through to normal NSFW handling
                return $prompt;
            }

            // AFFAIR: Married but partner is NOT spouse - use affair prompts. A promiscuous-marked
            // NPC skips the affair-guilt framing (the mark removes the affair floor) and falls
            // through to her tier_slut_<tier> scene set below.
            if (self::isAffairScenario($npcName, $partnerName)
                && !(function_exists('aiagentNsfwIsSlutNpc') && aiagentNsfwIsSlutNpc($npcName))) {
                $spouseName = self::getSpouseName($npcName);
                $prompt = $isNpcPromptContext
                    ? self::getNpcAffairTierPrompt($tier, $partnerName, $spouseName, $npcName)
                    : self::getAffairTierPrompt($tier, $partnerName, $spouseName);
                $prompt = str_replace('#AFFINITY#', $affinity, $prompt);
                $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);
                error_log("[NSFW_REL] Affair detected for $npcName with $partnerName (spouse: $spouseName, context: {$promptContext})");
                // After affair prompt, fall through to normal NSFW handling
                return $prompt;
            }
        }

        // REGULAR: Not married, or partner info not available - use standard prompts
        $prompts = self::loadPromptSettings();
        $promptKey = $isProstitute ? "tier_prost_{$tier}" : "tier_{$tier}";

        // Get prompt from database - regular-NPC safety net for profileless/fresh-DB (covers NPC-NPC, OStim + SexLab)
        $prompt = $prompts[$promptKey] ?? '';
        // PROMISCUOUS MARK: is_slut NPCs swap to the tier_slut_<tier> scene set.
        $slutTierText = self::getSlutTierPromptText($npcName, $tier, $isProstitute, $prompts);
        if ($slutTierText !== null) { $prompt = $slutTierText; }
        if (empty($prompt) && !$isProstitute) {
            require_once __DIR__ . "/nsfw_data.php";
            $_defTiers = NsfwData::getDefaultTierPrompts();
            $prompt = $_defTiers[ucfirst($tier)]['regular'] ?? ($_defTiers['Neutral']['regular'] ?? '');
        }
        if (empty($prompt)) {
            error_log("[NSFW_REL] ERROR: No prompt for '{$promptKey}' - save prompts in NSFW Config UI");
        }
        $prompt = self::replaceRouteActorPlaceholders($prompt, $partnerName, $promptContext, $npcName);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);
        $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);

        return $prompt;
    }

    private static function replaceNpcScenePlaceholders($prompt, $partnerName, $spouseName = '', $npcName = '') {
        $playerName = $GLOBALS['PLAYER_NAME'] ?? 'Player';
        $prompt = str_replace('#PRIMARY_PARTNER#', $partnerName, $prompt);
        $prompt = str_replace(['#NPC_SPOUSE#', '#SPOUSE#'], ($spouseName !== '' ? $spouseName : $partnerName), $prompt);
        $prompt = str_replace('#NPC_NAME#', $npcName, $prompt);
        $prompt = str_replace('#PLAYER_NAME#', $playerName, $prompt);
        return $prompt;
    }

    private static function getPlayerRouteName($fallback = 'Player') {
        $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? ''));
        if ($playerName === '') {
            $playerName = trim((string)$fallback);
        }
        return ($playerName !== '') ? $playerName : 'Player';
    }

    private static function isPlayerRouteName($targetName) {
        $target = strtolower(trim((string)$targetName));
        if ($target === '' || $target === 'player' || $target === 'the player') {
            return true;
        }

        $playerName = strtolower(self::getPlayerRouteName(''));
        if ($playerName === '') {
            return false;
        }

        return $target === $playerName || strpos($target, $playerName . ' ') === 0;
    }

    private static function replacePlayerRoutePlaceholders($prompt, $playerName = 'Player', $npcName = '', $spouseName = '') {
        $playerName = self::getPlayerRouteName($playerName);
        $prompt = str_replace('#PLAYER_NAME#', $playerName, $prompt);
        $prompt = str_replace('#NPC_NAME#', $npcName, $prompt);
        if ($spouseName !== '') {
            $prompt = str_replace(['#NPC_SPOUSE#', '#SPOUSE#'], $spouseName, $prompt);
        }
        return $prompt;
    }

    private static function replaceRouteActorPlaceholders($prompt, $partnerName, $promptContext = 'player', $npcName = '', $spouseName = '') {
        if (strtolower((string)$promptContext) === 'npc') {
            return self::replaceNpcScenePlaceholders($prompt, $partnerName, $spouseName, $npcName);
        }
        return self::replacePlayerRoutePlaceholders($prompt, $partnerName, $npcName, $spouseName);
    }

    private static function getDefaultPromptOverride($key) {
        require_once __DIR__ . "/nsfw_data.php";
        if (function_exists('nsfw_default_prompt_overrides')) {
            $defaults = nsfw_default_prompt_overrides();
            return $defaults[$key] ?? '';
        }
        return '';
    }

    private static function getNpcSceneTierPrompt($tier, $affinity, $isProstitute, $partnerName, $npcName = '') {
        $npcIsSlutMark = !$isProstitute && trim((string)$npcName) !== ''
            && function_exists('aiagentNsfwIsSlutNpc') && aiagentNsfwIsSlutNpc($npcName);
        $roleLine = $isProstitute
            ? 'This NPC is marked as a prostitute / sex worker; let that professional context color the response.'
            : ($npcIsSlutMark
                ? 'This NPC is openly promiscuous (never charges); let that eager, casual-desire disposition color the response.'
                : 'Use this relationship tier as emotional context only.');
        $prompt = "#NPC_NAME# is in an NPC-to-NPC intimate scene with #PRIMARY_PARTNER#. Relationship tier toward #PRIMARY_PARTNER#: #TIER# (#AFFINITY# affinity). {$roleLine} Do not run the player affinity, arousal, refusal, or scene-stop gate for this NPC-only route. React through #NPC_NAME#'s speech style, profile, current scene, and relationship state.";
        $prompt = self::replaceNpcScenePlaceholders($prompt, $partnerName, '', $npcName);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);
        $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);
        return $prompt;
    }

    /**
     * Get tier prompt for slaves
     * Slaves always comply - affinity only affects their emotional response
     *
     * @param int $affinity The affinity score (-100 to +100)
     * @param string $partnerName Owner/initiator name for placeholder replacement
     * @return string The slave tier prompt
     */
    // PROMISCUOUS MARK (2026-07-10): a regular NPC carrying is_slut swaps her per-tier SCENE prompt
    // to tier_slut_<tier> (Promiscuous (Global) section: eager Acquainted+ acceptance, RefuseSex
    // gates below). Returns the prompt text, or null when the mark/set does not apply so callers
    // fall through to the regular tier_<tier> path untouched. Blank saved key falls back to the
    // shipped default so a fresh DB never emits empty scene context for a marked NPC.
    private static function getSlutTierPromptText($npcName, $tier, $isProstitute, $prompts) {
        if ($isProstitute) { return null; }
        $npcName = trim((string)$npcName);
        if ($npcName === '' || !function_exists('aiagentNsfwIsSlutNpc') || !aiagentNsfwIsSlutNpc($npcName)) { return null; }
        $key = "tier_slut_{$tier}";
        $text = trim((string)($prompts[$key] ?? ''));
        if ($text === '') { $text = trim((string)self::getDefaultPromptOverride($key)); }
        return ($text !== '') ? $text : null;
    }

    public static function getSlaveTierPrompt($affinity, $partnerName = 'Owner') {
        $tier = strtolower(RelationshipManager::getTierLabel($affinity));

        $prompts = self::loadPromptSettings();
        $promptKey = "tier_slave_{$tier}";

        // Get prompt from database - NO HARDCODED FALLBACKS
        $prompt = $prompts[$promptKey] ?? '';
        if (empty($prompt)) {
            error_log("[NSFW_REL] ERROR: No prompt for '{$promptKey}' - save prompts in NSFW Config UI");
        }
        $prompt = self::replacePlayerRoutePlaceholders($prompt, $partnerName);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);
        $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);

        // Prepend fiction frame for model safety context
        $fictionFrame = self::getSlaveryFictionFrame();
        if (!empty($fictionFrame)) {
            $prompt = $fictionFrame . "\n\n" . $prompt;
        }

        return $prompt;
    }

    /**
     * Get the slavery fiction frame prompt for model safety
     * This is prepended to ALL slavery-related prompts throughout the scene
     *
     * @return string The fiction frame or empty string if not configured
     */
    public static function getSlaveryFictionFrame() {
        // Global on/off toggle (Prompts tab, next to the fiction frame prompt). Default ON.
        $prompts = self::loadPromptSettings();
        if (isset($prompts['slavery_fiction_frame_enabled']) && !$prompts['slavery_fiction_frame_enabled']) {
            return '';
        }
        return $prompts['slavery_fiction_frame'] ?? '';
    }

    /**
     * Persistent role/status overhead for NPCs marked in the SHARMAT UI.
     * This is character context, not scene gating. Scene-specific tier prompts still decide the active route.
     */
    public static function getRoleStatusOverhead($npcName, $partnerName = null) {
        $npcName = trim((string)$npcName);
        if ($npcName === '') {
            return '';
        }
        if (function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($npcName)) {
            return '';
        }
        if (function_exists('aiagentNsfwIsProtectedNpc') && aiagentNsfwIsProtectedNpc($npcName)) {
            return '';
        }

        require_once __DIR__ . "/nsfw_data.php";
        $extended = NsfwNpcData::get($npcName);
        if (!is_array($extended)) {
            $extended = [];
        }

        // Prostitute role-status is already covered by the per-tier relationship overhead
        // (getRelationshipOverhead -> relationship_overhead_prostitute_<tier>), so only SLAVES still get a
        // separate status overhead here - avoids stating "she is a prostitute" twice in the same prompt.
        $isSlave = !empty($extended['is_slave']);
        if (!$isSlave) {
            return '';
        }

        $prompts = self::loadPromptSettings();
        $prompt = trim((string)($prompts['slave_status_overhead'] ?? self::getDefaultPromptOverride('slave_status_overhead')));
        if ($prompt === '') {
            return '';
        }

        $prompt = self::replaceRoleOverheadPlaceholders($prompt, $npcName, $partnerName, $extended, true);

        return self::wrapXml('sharmat_slave_status', $prompt);
    }

    /**
     * Persistent role context for slave/prostitute NPCs.
     * This is general identity/occupation context, separate from relationship-tier scene gating.
     */
    public static function getRoleContextOverhead($npcName, $partnerName = null) {
        $npcName = trim((string)$npcName);
        if ($npcName === '') {
            return '';
        }
        if (function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($npcName)) {
            return '';
        }
        if (function_exists('aiagentNsfwIsProtectedNpc') && aiagentNsfwIsProtectedNpc($npcName)) {
            return '';
        }

        require_once __DIR__ . "/nsfw_data.php";
        $extended = NsfwNpcData::get($npcName);
        if (!is_array($extended)) {
            $extended = [];
        }

        $isSlave = !empty($extended['is_slave']);
        $isProstitute = !$isSlave && !empty($extended['is_prostitute']);
        // Promiscuous mark gets its own persistent role context too (children never carry the mark;
        // the save guards block it and aiagentNsfwIsSlutNpc re-checks, but guard here as well).
        $isSlut = !$isSlave && !$isProstitute && !empty($extended['is_slut'])
            && !(function_exists('aiagentNsfwIsChildNpc') && aiagentNsfwIsChildNpc($npcName));
        if (!$isSlave && !$isProstitute && !$isSlut) {
            return '';
        }

        $prompts = self::loadPromptSettings();
        $promptKey = $isSlave ? 'slave_role_context' : ($isProstitute ? 'prostitute_role_context' : 'slut_role_context');
        $prompt = trim((string)($prompts[$promptKey] ?? self::getDefaultPromptOverride($promptKey)));
        // Do NOT early-return on an empty base overhead. An INELIGIBLE relationship type must STILL receive the
        // "not sexually available" addendum below even when the per-tier overhead text is blank (user directive
        // 2026-06-29: a Bonded "transactional" NPC was getting no refusal addendum because this returned early -
        // the special route was short-circuited). If the whole block is still empty after the addendum, we return ''.

        $prompt = self::replaceRoleOverheadPlaceholders($prompt, $npcName, $partnerName, $extended, $isSlave);

        return self::wrapXml($isSlave ? 'sharmat_slave_role_context' : ($isProstitute ? 'sharmat_prostitute_role_context' : 'sharmat_slut_role_context'), $prompt);
    }

    /**
     * Relationship overhead for every NPC turn. This is the broad, current relationship/role layer that
     * sits above scene/touch/sex prompts and is recalculated every request to avoid stale context.
     */
    public static function getRelationshipOverhead($npcName, $partnerName = null, $promptContext = 'player') {
        $npcName = trim((string)$npcName);
        if ($npcName === '') {
            return '';
        }
        if (function_exists('nsfwIsNarratorName') && nsfwIsNarratorName($npcName)) {
            return '';
        }

        require_once __DIR__ . "/nsfw_data.php";
        $extended = NsfwNpcData::get($npcName);
        if (!is_array($extended)) {
            $extended = [];
        }

        $isSlave = !empty($extended['is_slave']);
        $isProstitute = !$isSlave && !empty($extended['is_prostitute']);
        $family = $isSlave ? 'slave' : ($isProstitute ? 'prostitute' : 'regular');

        // PROMISCUOUS MARK + OPEN MODE (2026-07-10): an NPC marked promiscuous (is_slut, NPC
        // Settings tab) uses the promiscuous overhead family (relationship_overhead_slut_<tier>)
        // instead of the regular one; slaves and prostitutes keep their own lanes and children
        // never swap. Open mode keeps the regular texts (the open_mode_notice carries the world
        // rules) but both must also bypass the type-ineligibility addendum below, which otherwise
        // orders a per-turn RefuseSex and fights the eligibility gate.
        $ovIsChild = function_exists('aiagentNsfwIsProtectedNpc') && aiagentNsfwIsProtectedNpc($npcName);
        $ovOpenMode = !$ovIsChild && function_exists('aiagentNsfwOpenMode') && aiagentNsfwOpenMode();
        $ovSlutMode = !$ovIsChild && !$ovOpenMode && !empty($extended['is_slut']);
        if ($family === 'regular' && $ovSlutMode) { $family = 'slut'; }

        $playerName = self::getPlayerRouteName();
        $partnerName = trim((string)($partnerName ?? ''));
        if ($partnerName === '') {
            $partnerName = $playerName;
        }

        try {
            $isNpcPromptContext = (strtolower((string)$promptContext) === 'npc') && !self::isPlayerRouteName($partnerName);
            $relationship = $isNpcPromptContext
                ? RelationshipManager::getRelationship($npcName, $partnerName)
                : RelationshipManager::getPlayerRelationship($npcName);
        } catch (Exception $e) {
            $relationship = ['aff' => 0, 'tier' => 'Neutral', 'type' => 'Unknown'];
        }

        $tier = strtolower((string)($relationship['tier'] ?? RelationshipManager::getTierLabel((int)($relationship['aff'] ?? 0))));
        if ($tier === '') {
            $tier = 'neutral';
        }

        $prompts = self::loadPromptSettings();
        $promptKey = "relationship_overhead_{$family}_{$tier}";

        // Read the type-gate master toggle before choosing the base overhead.
        // Legacy databases can retain old editable overheads that explicitly
        // forbid romance/intimacy. Reusing those while Open Mode is on, or while
        // the type gate is off, recreates the very gate the user disabled.
        $rtEligibleOv = ['romantic', 'crush', 'ex'];
        $rtGateEnabledOv = true;
        $rtRowOv = (isset($GLOBALS["db"]) && $GLOBALS["db"]) ? $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_reltypes'") : null;
        if ($rtRowOv && !empty($rtRowOv['value'])) {
            $rtCfgOv = json_decode($rtRowOv['value'], true);
            if (is_array($rtCfgOv)) {
                $rtGateEnabledOv = array_key_exists('enabled', $rtCfgOv) ? (bool)$rtCfgOv['enabled'] : true;
                if (is_array($rtCfgOv['eligible_types'] ?? null)) {
                    $rtEligibleOv = array_map('strtolower', $rtCfgOv['eligible_types']);
                }
            }
        }

        $useNonGatingRegularOverhead = ($family === 'regular')
            && empty($isNpcPromptContext)
            && ($ovOpenMode || !$rtGateEnabledOv);
        if ($useNonGatingRegularOverhead && function_exists('aiagentNsfwBuildNonGatingRelationshipOverhead')) {
            $prompt = aiagentNsfwBuildNonGatingRelationshipOverhead(
                $npcName,
                $partnerName,
                $relationship,
                $ovOpenMode
            );
        } else {
            $prompt = trim((string)($prompts[$promptKey] ?? self::getDefaultPromptOverride($promptKey)));
        }
        // Do NOT early-return on a blank base overhead: an INELIGIBLE relationship TYPE must STILL receive the
        // "not sexually available / RefuseSex" addendum below even when the per-tier base text is empty. Without
        // this, a professional/bonded NPC with a blank base got NO refusal instruction every turn and freely
        // initiated sex. The trim()==='' check at the end returns '' if nothing (base + addendum) was added.
        // (Mirrors the same fix already applied in getRoleContextOverhead.)

        // COMPOSE THE OVERHEAD STACK (regular NPCs, player route): base overhead + relationship-tier tag +
        // (if married) spouse-identity tag + (if her relationship TYPE is NOT checked sex-eligible in the UI,
        // Friendly+) an explicit pre-emptive RefuseSex instruction. All appended into the SINGLE overhead block
        // so they land on her in one go. Editable via the Prompts panel (relationship_overhead_tier_tag /
        // relationship_overhead_spouse_tag); the refusal text comes from the Relationship Types section.
        if (($family === 'regular' || $family === 'slut') && empty($isNpcPromptContext)) {
            // The tier-tag add-on (UI: relationship_overhead_tier_tag) is the INELIGIBLE-TYPE addendum: it applies
            // ONLY to NPCs whose relationship TYPE is NOT one of the UI-selected sex-eligible types. Eligible types
            // (e.g. romantic, crush) are exempt and never see it. Was injecting unconditionally, which force-refused
            // even bonded romantic NPCs.
            $relTypeLowerOv = aiagentNsfwCanonicalRelationshipType($relationship['type'] ?? '');
            $typeIsEligibleOv = ($relTypeLowerOv !== '' && in_array($relTypeLowerOv, $rtEligibleOv, true));

            // OPEN MODE: no type is ineligible - the refusal addendum would fight the mode. SLUT
            // family (per-NPC mark or global mode): eligibility is affinity-driven from Acquaintance
            // (6) up, mirroring aiagentNsfwRelTypeSexEligible; below the floor the refusal addendum
            // still fires so the mechanical gate and the prompt agree.
            if ($ovOpenMode || !$rtGateEnabledOv) {
                $typeIsEligibleOv = true;
            } elseif ($family === 'slut') {
                // Mirror aiagentNsfwRelTypeSexEligible exactly: affinity floor 6 AND orientation
                // allows the player - so the prompt and the mechanical gate never disagree.
                $typeIsEligibleOv = ((int)($relationship['aff'] ?? 0) >= 6)
                    && (!function_exists('aiagentNsfwOrientationAllowsPlayer') || aiagentNsfwOrientationAllowsPlayer($npcName));
            }

            // RELDYN CONSENT (LOCAL hook, branch reldyn-consent): when RelDyn has published its decision for this NPC it IS the answer, the
            // same one aiagentNsfwRelTypeSexEligible returns, so the prompt and the mechanical gate never disagree. Null without RelDyn.
            $relDynOv = (function_exists('aiagentNsfwRelDynConsentDecision') && empty($isNpcPromptContext))
                ? aiagentNsfwRelDynConsentDecision($npcName) : null;
            if ($relDynOv !== null) {
                $typeIsEligibleOv = function_exists('aiagentNsfwRelDynConsentEligibility')
                    ? (aiagentNsfwRelDynConsentEligibility($npcName) !== false)
                    : !empty($relDynOv['allow']);
            }

            if (!$typeIsEligibleOv) {
                $tierTag = trim((string)($prompts['relationship_overhead_tier_tag']
                    ?? "Your current relationship tier with #PLAYER_NAME# is #TIER# (#AFFINITY# affinity)."));
                if ($tierTag !== '') { $prompt .= "\n" . $tierTag; }
            }

            // Spouse-affair notice: skipped in open mode (no permission structure exists) and for the
            // slut family (the mode/mark explicitly removes the affair floor); a promiscuous or
            // open-world spouse being warned "this would be an affair" every turn contradicts both.
            if (!$ovOpenMode && $family !== 'slut') {
                $overheadSpouse = '';
                try { $overheadSpouse = trim((string)self::getSpouseName($npcName)); } catch (Exception $e) { $overheadSpouse = ''; }
                if ($overheadSpouse !== '') {
                    $spouseTag = trim((string)($prompts['relationship_overhead_spouse_tag']
                        ?? "You are married to #SPOUSE#. #PLAYER_NAME# is not your spouse; any sexual intimacy with #PLAYER_NAME# would be an affair on your part."));
                    if ($spouseTag !== '') { $prompt .= "\n" . $spouseTag; }
                }
            }

            // Inject the kind-decline instruction ONLY for an INELIGIBLE relationship type (eligible types like
            // romantic/crush must NEVER be told to refuse). Fires even when no custom refusal text is configured
            // (default fallback), so a blank config still gates a professional/student/etc. NPC every single turn.
            if (!$typeIsEligibleOv) {
                $typeRefusal = self::getRelTypeGateRefusal($npcName, $partnerName);
                $typeRefusal = (is_string($typeRefusal) && trim($typeRefusal) !== '') ? trim($typeRefusal) : "Your relationship with #PLAYER_NAME# is not a sexual one.";
                // RelDyn's own words for why (feelings, the NPC's name, no pronoun) replace the generic relationship-type line
                if ($relDynOv !== null && ($relDynOv['felt'] ?? '') !== '') { $typeRefusal = $relDynOv['felt']; }
                $prompt .= "\n" . $typeRefusal
                    . " You are NOT sexually available to #PLAYER_NAME#. If they make a sexual advance, kindly and in character decline it and use the RefuseSex action.";
            } elseif ($relDynOv !== null && ($relDynOv['felt'] ?? '') !== '') {
                // RelDyn says yes but not wholeheartedly (unsure, or giving in): the model plays it
                $prompt .= "\n" . $relDynOv['felt'];
            }
        }

        if (trim($prompt) === '') {
            return ''; // nothing to inject (e.g. eligible type with a blank per-tier overhead)
        }
        $prompt = self::replaceRelationshipOverheadPlaceholders($prompt, $npcName, $partnerName, $extended, $relationship, $isSlave);
        return self::wrapXml("sharmat_relationship_overhead", $prompt);
    }

    private static function replaceRoleOverheadPlaceholders($prompt, $npcName, $partnerName, $extended, $isSlave) {
        if (!is_array($extended)) {
            $extended = [];
        }

        try {
            $relationship = RelationshipManager::getPlayerRelationship($npcName);
        } catch (Exception $e) {
            $relationship = ['aff' => 0, 'tier' => 'Neutral', 'type' => 'Unknown'];
        }

        $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? ''));
        if ($playerName === '') {
            $playerName = 'Player';
        }
        $partnerName = trim((string)($partnerName ?? ''));
        if ($partnerName === '') {
            $partnerName = $playerName;
        }

        $pricing = is_array($extended['prostitute_pricing'] ?? null) ? $extended['prostitute_pricing'] : [];
        $prostituteType = $extended['prostitute_type'] ?? ($pricing['prostitute_type'] ?? 'unspecified');
        $paymentType = $pricing['payment_type'] ?? 'gold';
        $motivation = $pricing['motivation'] ?? 'professional';
        $price = self::getProstituteFlatPriceLabel($extended, (int)($relationship['aff'] ?? 0));
        $tier = (string)($relationship['tier'] ?? 'Neutral');
        $affinity = (string)($relationship['aff'] ?? '0');
        $relType = (string)($relationship['type'] ?? 'Unknown');

        $prompt = str_replace(
            [
                '#NPC_NAME#',
                '#PLAYER_NAME#',
                '#PRIMARY_PARTNER#',
                '#OWNER#',
                '#MASTER#',
                '#AFFINITY#',
                '#TIER#',
                '#TYPE#',
                '#REL_TYPE#',
                '#ROLE#',
                '#PROSTITUTE_TYPE#',
                '#PAYMENT_TYPE#',
                '#MOTIVATION#',
                '#PRICE#',
            ],
            [
                $npcName,
                $playerName,
                $partnerName,
                $playerName,
                $playerName,
                $affinity,
                $tier,
                $relType,
                $relType,
                $isSlave ? 'slave' : 'prostitute',
                $prostituteType,
                $paymentType,
                $motivation,
                $price,
            ],
            $prompt
        );

        return $prompt;
    }

    private static function replaceRelationshipOverheadPlaceholders($prompt, $npcName, $partnerName, $extended, $relationship, $isSlave) {
        if (!is_array($extended)) {
            $extended = [];
        }
        if (!is_array($relationship)) {
            $relationship = ['aff' => 0, 'tier' => 'Neutral', 'type' => 'Unknown'];
        }

        $playerName = self::getPlayerRouteName();
        $pricing = is_array($extended['prostitute_pricing'] ?? null) ? $extended['prostitute_pricing'] : [];
        $prostituteType = $extended['prostitute_type'] ?? ($pricing['prostitute_type'] ?? 'unspecified');
        $paymentType = $pricing['payment_type'] ?? 'gold';
        $motivation = $pricing['motivation'] ?? 'professional';
        $spouseName = '';
        try {
            $spouseName = self::getSpouseName($npcName);
        } catch (Exception $e) {
            $spouseName = '';
        }

        $prompt = str_replace(
            [
                '#NPC_NAME#',
                '#PLAYER_NAME#',
                '#PRIMARY_PARTNER#',
                '#OWNER#',
                '#MASTER#',
                '#AFFINITY#',
                '#TIER#',
                '#TYPE#',
                '#REL_TYPE#',
                '#ROLE#',
                '#PROSTITUTE_TYPE#',
                '#PAYMENT_TYPE#',
                '#MOTIVATION#',
                '#PRICE#',
                '#SPOUSE#',
                '#NPC_SPOUSE#',
            ],
            [
                $npcName,
                $playerName,
                $partnerName,
                $playerName,
                $playerName,
                (string)($relationship['aff'] ?? '0'),
                (string)($relationship['tier'] ?? 'Neutral'),
                (string)($relationship['type'] ?? 'Unknown'),
                (string)($relationship['type'] ?? 'Unknown'),
                $isSlave ? 'slave' : (!empty($extended['is_prostitute']) ? 'prostitute' : 'regular'),
                $prostituteType,
                $paymentType,
                $motivation,
                self::getProstituteFlatPriceLabel($extended, (int)($relationship['aff'] ?? 0)),
                $spouseName !== '' ? $spouseName : 'your spouse',
                $spouseName !== '' ? $spouseName : 'your spouse',
            ],
            $prompt
        );

        return $prompt;
    }

    private static function getProstituteFlatPriceLabel($extended, $affinity = null) {
        if (!is_array($extended)) {
            $extended = [];
        }
        $pricing = is_array($extended['prostitute_pricing'] ?? null) ? $extended['prostitute_pricing'] : [];
        $flatPrice = (int)($extended['prostitute_price'] ?? 0);
        if ($flatPrice < 1 && !empty($pricing['individual_acts']) && is_array($pricing['individual_acts'])) {
            $acts = $pricing['individual_acts'];
            $flatPrice = (int)($acts['full_both'] ?? $acts['full_vaginal'] ?? (reset($acts) ?: 0));
        }
        if ($flatPrice < 1) {
            $flatPrice = 100;
        }
        // Apply the affinity-tier discount/premium so #PRICE# matches the quoted price + payment gate.
        if ($affinity !== null && function_exists('aiagentNsfwProstituteAffinityPrice')) {
            $flatPrice = aiagentNsfwProstituteAffinityPrice($flatPrice, (int)$affinity);
        }
        return "{$flatPrice} gold";
    }

    // Per-NPC slave cue override: this slave's custom Scene Cues / Climax / Aftermath text if set, else '' (falls back to global)
    private static function getPerNpcSlaveCue($field) {
        $npcName = $GLOBALS["HERIKA_NAME"] ?? '';
        if ($npcName === '') return '';
        require_once __DIR__ . "/nsfw_data.php";
        $ext = NsfwNpcData::get($npcName);
        $val = $ext['slave_speak_styles'][$field] ?? '';
        return is_string($val) ? trim($val) : '';
    }

    // REMOVED: getDefaultSlavePrompt() - prompts come from database only (conf_opts.aiagent_nsfw_prompts)
    // Lookup key: tier_slave_{tier} e.g. tier_slave_hostile, tier_slave_neutral

    /**
     * Get NPC's spousal information from nsfw_npc_data table
     *
     * @param string $npcName The NPC's name
     * @return array ['spousal_status' => string, 'spouse_names' => array]
     */
    private static function getNpcSpousalInfo($npcName) {
        try {
            // Get from nsfw_npc_data table (NOT core_npc_master.extended_data)
            require_once __DIR__ . "/nsfw_data.php";
            $extended = NsfwNpcData::get($npcName);

            if (empty($extended)) {
                return ['spousal_status' => 'single', 'spouse_names' => []];
            }

            $status = $extended['spousal_status'] ?? 'single';
            $namesStr = $extended['spouse_names'] ?? '';

            // Parse comma-separated spouse names into array
            $names = [];
            if (!empty($namesStr)) {
                $names = array_map('trim', explode(',', $namesStr));
                $names = array_filter($names); // Remove empty strings
            }

            // Resolve the literal "Player" token to the player's character name, so an NPC can be
            // marked married to the player without knowing the playthrough-specific name.
            $playerName = $GLOBALS["PLAYER_NAME"] ?? '';
            if ($playerName !== '') {
                foreach ($names as $k => $n) {
                    if (strcasecmp($n, 'Player') === 0) {
                        $names[$k] = $playerName;
                    }
                }
            }

            return ['spousal_status' => $status, 'spouse_names' => array_values($names)];
        } catch (Exception $e) {
            error_log("[NSFW_REL] Failed to get spousal info for $npcName: " . $e->getMessage());
            return ['spousal_status' => 'single', 'spouse_names' => []];
        }
    }

    // ============================================
    // MARRIAGE SCENARIO DETECTION
    // ============================================
    // Marriage: Partner IS spouse (married couple having sex)
    // Affair: Partner is NOT spouse (cheating scenario)
    // Group Marriage: Player initiates scene with married NPCs
    // ============================================

    /**
     * Check if this is a marriage scenario (partner IS the NPC's spouse)
     * Both participants are married to each other
     *
     * @param string $npcName The NPC's name
     * @param string $partnerName The partner's name
     * @return bool True if this is a marriage scene (NPC is married to partner)
     */
    public static function isMarriageScenario($npcName, $partnerName) {
        $spousalInfo = self::getNpcSpousalInfo($npcName);

        // Not married = not a marriage scene
        if ($spousalInfo['spousal_status'] !== 'married') {
            return false;
        }

        // No spouse names configured = not a marriage scene
        if (empty($spousalInfo['spouse_names'])) {
            return false;
        }

        // Check if partner matches any spouse name (case-insensitive)
        $partnerLower = strtolower(trim($partnerName));
        foreach ($spousalInfo['spouse_names'] as $spouse) {
            if (strtolower(trim($spouse)) === $partnerLower) {
                return true; // Partner IS the spouse - this is a marriage scene
            }
        }

        return false;
    }

    /**
     * Check if this is an affair scenario (partner is not the NPC's spouse)
     *
     * @param string $npcName The NPC's name
     * @param string $partnerName The partner initiating intimacy
     * @return bool True if this is an affair (NPC is married but partner is not their spouse)
     */
    public static function isAffairScenario($npcName, $partnerName) {
        $spousalInfo = self::getNpcSpousalInfo($npcName);

        // Not married = not an affair
        if ($spousalInfo['spousal_status'] !== 'married') {
            return false;
        }

        // No spouse names configured = treat as regular scene
        if (empty($spousalInfo['spouse_names'])) {
            return false;
        }

        // Check if partner matches any spouse name (case-insensitive)
        $partnerLower = strtolower(trim($partnerName));
        foreach ($spousalInfo['spouse_names'] as $spouse) {
            if (strtolower(trim($spouse)) === $partnerLower) {
                return false; // Partner IS the spouse - not an affair
            }
        }

        // Partner doesn't match any spouse - this is an affair
        return true;
    }

    /**
     * Get the spouse name for replacement in prompts
     *
     * @param string $npcName The NPC's name
     * @return string The first spouse name or empty string
     */
    public static function getSpouseName($npcName) {
        $spousalInfo = self::getNpcSpousalInfo($npcName);
        return !empty($spousalInfo['spouse_names']) ? $spousalInfo['spouse_names'][0] : '';
    }

    // ============================================
    // MARRIAGE PROMPTS (Spouse + Spouse)
    // ============================================
    // These prompts trigger when two married partners have sex WITH EACH OTHER
    // Tiers reflect the quality of the marriage based on affinity
    // ============================================

    /**
     * Get marriage tier prompt based on affinity between spouses
     * Used when two married partners are having sex with each other
     *
     * @param string $tier The tier name (lowercase)
     * @param string $spouseName The spouse's name
     * @return string The marriage prompt
     */
    public static function getMarriageSpousePrompt($tier, $spouseName) {
        $prompts = self::loadPromptSettings();
        $promptKey = "marriage_spouse_{$tier}";

        $prompt = $prompts[$promptKey] ?? '';
        if (empty($prompt)) {
            // Blob key not saved yet - use the built-in default so marriage scenes still get flavor
            $prompt = self::getDefaultMarriageSpousePrompt($tier);
        }
        $prompt = str_replace('#SPOUSE#', $spouseName, $prompt);

        return $prompt;
    }

    public static function getNpcMarriageSpousePrompt($tier, $spouseName, $npcName = '') {
        $prompts = self::loadPromptSettings();
        $promptKey = "npc_marriage_spouse_{$tier}";

        $prompt = $prompts[$promptKey] ?? '';
        if (empty($prompt)) {
            $prompt = $prompts['npc_marriage'] ?? '';
        }
        if (empty($prompt)) {
            $prompt = self::getMarriageSpousePrompt($tier, $spouseName);
        }

        return self::replaceNpcScenePlaceholders($prompt, $spouseName, $spouseName, $npcName);
    }

    /**
     * Get default marriage prompts for married couples having sex
     * These reflect the quality/health of the marriage based on affinity
     *
     * @param string $tier The tier name (lowercase)
     * @return string Default marriage prompt
     */
    private static function getDefaultMarriageSpousePrompt($tier) {
        $defaults = [
            // Positive affinity: loving, healthy marriage
            'bonded' => 'You are making love with your beloved spouse #SPOUSE#. This is your soulmate, your everything. Every touch is electric, every moment sacred. You have never loved anyone more. Pure passion, complete devotion.',
            'devoted' => 'You are intimate with your dear spouse #SPOUSE#. Deep love fills you. Your marriage is strong and passionate. You cherish them completely. Tender, loving, devoted.',
            'fond' => 'You are with your spouse #SPOUSE# in an intimate moment. Your marriage is good, comfortable. You care for them deeply. Warm, familiar, affectionate.',
            'friendly' => 'You are being intimate with your spouse #SPOUSE#. Your marriage is pleasant enough. You like each other. Comfortable, routine but still enjoyable.',
            'acquaintance' => 'You are with your spouse #SPOUSE# intimately. Your marriage is... functional. You are still learning each other. Somewhat awkward, uncertain, but trying.',

            // Neutral: going through the motions
            'neutral' => 'You are intimate with your spouse #SPOUSE#. Your marriage is neither good nor bad. You do this because you are married. Mechanical, dutiful, unfulfilling.',

            // Negative affinity: troubled marriage
            'wary' => 'You are with your spouse #SPOUSE# intimately. Trust issues plague your marriage. You watch them carefully even now. Guarded, tense, suspicious.',
            'cold' => 'You are being intimate with your spouse #SPOUSE#. Your marriage is cold, loveless. You feel nothing. Going through the motions. Distant, disconnected.',
            'resentful' => 'You are with your spouse #SPOUSE#. You resent this marriage, resent them. Bitter thoughts fill you even now. Anger simmers beneath the surface.',
            'hateful' => 'You are forced into intimacy with your spouse #SPOUSE#. You hate them. Every touch disgusts you. You dream of escape, of freedom from this prison of a marriage.',
            'hostile' => 'You are with your spouse #SPOUSE# but you despise them utterly. This marriage is a battlefield. You endure this only out of obligation or circumstance. Rage, disgust, trapped.',
        ];

        return $defaults[$tier] ?? $defaults['neutral'];
    }

    // ============================================
    // AFFAIR PROMPTS (Partner is NOT Spouse)
    // ============================================
    // These prompts trigger when a married NPC has sex with someone OTHER than their spouse
    // Tiers reflect willingness to cheat based on affinity with the affair partner
    // ============================================

    /**
     * Get affair tier prompt based on affinity with the affair partner
     * Used when married NPC is cheating with someone other than their spouse
     *
     * @param string $tier The tier name (lowercase)
     * @param string $partnerName The affair partner's name
     * @param string $spouseName The NPC's spouse's name
     * @return string The affair prompt
     */
    public static function getAffairTierPrompt($tier, $partnerName, $spouseName) {
        $prompts = self::loadPromptSettings();
        $promptKey = "affair_{$tier}";

        $prompt = $prompts[$promptKey] ?? '';
        if (empty($prompt)) {
            // Blob key not saved yet - use the built-in default so affair scenes still get flavor
            $prompt = self::getDefaultAffairPrompt($tier);
        }
        $prompt = self::replacePlayerRoutePlaceholders($prompt, $partnerName, '', $spouseName);

        return $prompt;
    }

    public static function getNpcAffairTierPrompt($tier, $partnerName, $spouseName, $npcName = '') {
        $prompts = self::loadPromptSettings();
        $promptKey = "npc_affair_{$tier}";

        $prompt = $prompts[$promptKey] ?? '';
        if (empty($prompt)) {
            $prompt = $prompts['npc_affair'] ?? '';
        }
        if (empty($prompt)) {
            $prompt = self::getDefaultPromptOverride('npc_affair');
        }
        if (empty($prompt)) {
            $prompt = '(#NPC_NAME# is married to #NPC_SPOUSE#, but #NPC_NAME# is being intimate with #PRIMARY_PARTNER# instead. This is an affair. React according to your personality, relationship state, and current scene.)';
        }

        return self::replaceNpcScenePlaceholders($prompt, $partnerName, $spouseName, $npcName);
    }

    /**
     * Get default affair prompts for cheating scenarios
     * These reflect willingness to cheat based on affinity with the affair partner
     *
     * @param string $tier The tier name (lowercase)
     * @return string Default affair prompt
     */
    private static function getDefaultAffairPrompt($tier) {
        $defaults = [
            // Positive affinity: willing affair, guilt varies by tier
            'bonded' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but #PLAYER_NAME# is your soulmate, your true love. What you have with #PLAYER_NAME# transcends your marriage. No guilt, only passion. This is where you belong.',
            'devoted' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but you are deeply in love with #PLAYER_NAME#. This affair feels more real than your marriage. Passionate, loving, only slight guilt.',
            'fond' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but you have strong feelings for #PLAYER_NAME#. The affair excites you. Tender and passionate, though guilt lingers in quiet moments.',
            'friendly' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but you enjoy #PLAYER_NAME#. This affair is fun, thrilling. You like them. Guilt is there but the excitement wins.',
            'acquaintance' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but #PLAYER_NAME# is new and exciting. This affair is risky, thrilling. Nervous but curious. Significant guilt but you proceed.',

            // Neutral: conflicted
            'neutral' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. #PLAYER_NAME# is... someone. You are not sure why you are doing this. Conflicted, uncertain. Heavy guilt but something keeps you here.',

            // Negative affinity: rejection of the affair
            'wary' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You do not trust #PLAYER_NAME# enough for this. Hesitant, pulling back. This feels wrong. You should not be here.',
            'cold' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You feel nothing for #PLAYER_NAME#. Why would you risk your marriage for this? Refusing. This is a mistake.',
            'resentful' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You resent #PLAYER_NAME# for even trying this. How dare they. Bitter refusal. Go back to your spouse.',
            'hateful' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You hate #PLAYER_NAME#. This is an insult to your marriage. Aggressive rejection. Get away from me.',
            'hostile' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You DESPISE #PLAYER_NAME#. This is assault. You will fight. You will tell #SPOUSE#. You will destroy them.',
        ];

        return $defaults[$tier] ?? $defaults['neutral'];
    }

    /**
     * LEGACY WRAPPER: Get marriage/affair tier prompt
     * @deprecated Use getAffairTierPrompt() or getMarriageSpousePrompt() directly
     */
    private static function getMarriageTierPrompt($tier, $partnerName, $spouseName) {
        // This is now an affair prompt (partner != spouse)
        return self::getAffairTierPrompt($tier, $partnerName, $spouseName);
    }

    /**
     * LEGACY WRAPPER: Get default marriage prompt
     * @deprecated Use getDefaultAffairPrompt() or getDefaultMarriageSpousePrompt() directly
     */
    private static function getDefaultMarriagePrompt($tier) {
        // This is now an affair prompt (partner != spouse)
        return self::getDefaultAffairPrompt($tier);
    }

    /**
     * Get all tier prompts for display/editing
     *
     * @return array Associative array of all tier prompts
     */
    public static function getAllTierPrompts() {
        $prompts = self::loadPromptSettings();

        $tiers = ['hostile', 'hateful', 'resentful', 'cold', 'wary', 'neutral',
                  'acquaintance', 'friendly', 'fond', 'devoted', 'bonded'];

        $result = [
            'regular' => [],
            'prostitute' => []
        ];

        foreach ($tiers as $tier) {
            // Get prompts from database - NO HARDCODED FALLBACKS
            $result['regular'][$tier] = $prompts["tier_{$tier}"] ?? '';
            $result['prostitute'][$tier] = $prompts["tier_prost_{$tier}"] ?? '';
        }

        return $result;
    }

    /**
     * Build the complete NSFW relationship context for prompt injection
     *
     * @param string $npcName The NPC's name
     * @param bool $isProstitute Whether the NPC is a prostitute
     * @return string Complete relationship context block
     */
    public static function buildRelationshipContext($npcName, $isProstitute = false) {
        $relationship = RelationshipManager::getPlayerRelationship($npcName);
        $tierPrompt = self::getTierPrompt($npcName, $isProstitute);

        $relationshipInfo = "Affinity: {$relationship['aff']} ({$relationship['tier']})\n";
        $relationshipInfo .= "Type: {$relationship['type']}";

        $context = self::wrapXml('relationship_context', $relationshipInfo) . "\n\n";
        $context .= self::wrapXml('emotional_state', $tierPrompt);

        return $context;
    }

    /**
     * LABEL ONLY (display / log) - NEVER gate sex on this. Consent is prompt-driven (AcceptSex / RefuseSex);
     * affinity only selects which relationship PROMPT is shown, it does not decide consent.
     * Returns 'eager'/'willing'/'reluctant'/'unwilling'.
     *
     * @param int $affinity The affinity score
     * @return string Affinity label (NOT a consent gate)
     */
    public static function getConsentLevel($affinity) {
        if ($affinity >= 56) return 'eager';      // Fond, Devoted, Bonded
        if ($affinity >= 6) return 'willing';     // Acquaintance, Friendly
        if ($affinity >= -30) return 'reluctant'; // Wary, Neutral
        return 'unwilling';                       // Cold, Resentful, Hateful, Hostile
    }

    /**
     * Load prompt settings from database
     *
     * @return array The prompt settings
     */
    private static function loadPromptSettings() {
        if (self::$promptCache !== null) {
            return self::$promptCache;
        }

        try {
            $row = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_prompts'");
            if ($row && !empty($row['value'])) {
                self::$promptCache = json_decode($row['value'], true) ?: [];
                // Debug: Log what tier prompts we loaded from DB
                $tierNeutral = self::$promptCache['tier_neutral'] ?? 'NOT SET';
                $tierNeutralLen = strlen($tierNeutral);
                error_log("[NSFW_REL] SUCCESS: Loaded prompts from DB. tier_neutral length: {$tierNeutralLen}");
                // Log full tier_neutral prompt
                if ($tierNeutralLen > 0) {
                    error_log("[NSFW_REL] tier_neutral: " . $tierNeutral);
                }
                // Check for the custom "RefuseSex" keyword
                if (strpos($tierNeutral, 'RefuseSex') !== false || strpos($tierNeutral, 'RefuseSex') !== false) {
                    error_log("[NSFW_REL] FOUND RefuseSex in tier_neutral - custom UI prompt loaded!");
                }
                return self::$promptCache;
            } else {
                error_log("[NSFW_REL] WARNING: No prompt settings in DB - using hardcoded defaults");
            }
        } catch (Exception $e) {
            error_log("[NSFW_REL] ERROR loading prompt settings: " . $e->getMessage());
        }

        // If we get here, DB is empty or errored - use hardcoded defaults
        // This should only happen on first install before user saves anything
        self::$promptCache = [];
        return self::$promptCache;
    }

    /**
     * Clear the prompt cache (call after saving new settings)
     */
    public static function clearCache() {
        self::$promptCache = null;
    }

    // REMOVED: getDefaultPrompt() - prompts come from database only (conf_opts.aiagent_nsfw_prompts)
    // Lookup keys: tier_{tier} for regular NPCs, tier_prost_{tier} for prostitutes
    // e.g. tier_neutral, tier_hostile, tier_prost_neutral, tier_prost_hostile

    /**
     * Get relationship emoji for UI display
     *
     * @param int $affinity The affinity score
     * @return string Emoji representing the tier
     */
    public static function getTierEmoji($affinity) {
        return RelationshipManager::getTierEmoji(
            RelationshipManager::getTierLabel($affinity)
        );
    }

    /**
     * RELATIONSHIP-TYPE GATE (UI-configured: conf_opts 'aiagent_nsfw_reltypes').
     *
     * If the NPC's relationship TYPE with the player is NOT checked sex-eligible, and affinity is
     * Friendly+ (Friendly/Fond/Devoted/Bonded), return the tier-toned polite refusal so the scene gets a
     * relationship-appropriate "we're not like that" instead of sex. Returns null when the gate does
     * NOT apply: gate disabled, partner isn't the player (player-only), type IS eligible, or affinity
     * is below Friendly (Acquaintance/Neutral/Wary/Hostile fall through to the normal affinity tier prompts).
     * Slave/prostitute exemption is enforced by the caller.
     *
     * @param string $npcName     The NPC being propositioned
     * @param string $partnerName The scene partner (must be the player for the gate to apply)
     * @return string|null The polite refusal prompt, or null if no rel-type refusal applies
     */
    public static function getRelTypeGateRefusal($npcName, $partnerName) {
        require_once __DIR__ . "/nsfw_data.php";

        // Open Mode owns the player-route permission policy. Never let this
        // lower-level helper reintroduce a relationship-type refusal if the
        // direct scene auto-accept has not happened yet.
        if (function_exists('aiagentNsfwOpenMode') && aiagentNsfwOpenMode()
            && (!function_exists('aiagentNsfwIsProtectedNpc') || !aiagentNsfwIsProtectedNpc($npcName))) {
            return null;
        }

        // Master config blob (same key the UI writes)
        $row = (isset($GLOBALS["db"]) && $GLOBALS["db"]) ? $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_reltypes'") : null;
        $cfg = ($row && !empty($row['value'])) ? json_decode($row['value'], true) : [];
        if (!is_array($cfg)) { $cfg = []; }

        // Master toggle (default on, matching the UI default)
        $enabled = array_key_exists('enabled', $cfg) ? (bool)$cfg['enabled'] : true;
        if (!$enabled) { return null; }

        // Player-only: never gate NPC<->NPC scenes
        $playerName = $GLOBALS['PLAYER_NAME'] ?? 'Player';
        if (strtolower($partnerName) !== strtolower($playerName)) { return null; }

        // Relationship type + affinity WITH THE PLAYER
        $rel = RelationshipManager::getPlayerRelationship($npcName);
        $relType = aiagentNsfwCanonicalRelationshipType($rel['type'] ?? '');
        $aff = (int)($rel['aff'] ?? 0);

        // Sex-eligible types go through the normal rel gate
        $eligible = $cfg['eligible_types'] ?? ['romantic', 'crush', 'ex'];
        $eligible = is_array($eligible) ? array_map('strtolower', $eligible) : [];
        if (in_array($relType, $eligible, true)) { return null; }

        // Tier comes from CHIM (single source of truth) - never hardcode the 56/76/91 boundaries.
        // getTierLabel() owns the bands; if CHIM rebalances them this gate tracks them automatically.
        $tier = strtolower(RelationshipManager::getTierLabel($aff));
        switch ($tier) {
            case 'bonded':  $prompt = $cfg['prompt_bonded']  ?? ''; break;
            case 'devoted': $prompt = $cfg['prompt_devoted'] ?? ''; break;
            case 'fond':     $prompt = $cfg['prompt_fond']     ?? ''; break;
            case 'friendly': $prompt = $cfg['prompt_friendly'] ?? ''; break;
            default:         return null; // Below Friendly -> normal affinity tier prompts handle it
        }
        // DEFAULT-IF-BLANK (user directive): an INELIGIBLE relationship type must ALWAYS produce a polite decline,
        // even when the UI prompt fields are empty. Those textareas get wiped whenever a config save submits blank
        // fields (handleSaveRelTypes writes $_POST['prompt_*'] ?? ''), which silently disabled the whole gate. The
        // TYPE (not the saved wording) decides whether she refuses; the text is only flavor, so fall back to a
        // sensible tier-toned default rather than no-op'ing.
        if (trim((string)$prompt) === '') {
            $gateDefaults = [
                'friendly' => "You like #PLAYER_NAME# and feel comfortable with them, but your relationship is not romantic or sexual. Kindly but clearly decline the advance and keep the boundary intact. Politely refuse.",
                'fond'     => "You have genuine affection for #PLAYER_NAME# and care about them, but your relationship with them isn't a romantic or sexual one - you're simply not involved that way. Warmly but firmly decline the advance without hurting the bond. Politely refuse.",
                'devoted'  => "You care deeply for #PLAYER_NAME#, but what the two of you share isn't romantic - the bond runs deep, just not that way. Gently and kindly turn down the advance while honoring how much they mean to you. Politely refuse.",
                'bonded'   => "#PLAYER_NAME# means the world to you, but your bond isn't a romantic or sexual one and you won't cross that line. Tenderly, lovingly decline - the closeness stays, the line stays. Politely refuse.",
            ];
            $prompt = $gateDefaults[$tier] ?? $gateDefaults['friendly'];
        }

        // Additive: if she is ALSO married, append the married-non-types clause
        $extended = NsfwNpcData::get($npcName);
        if (is_array($extended) && ($extended['spousal_status'] ?? 'single') === 'married') {
            $addon = $cfg['prompt_married_addon'] ?? '';
            if (!empty($addon)) {
                $spouse = $extended['spouse_names'] ?? '';
                $addon = str_replace('#SPOUSE#', ($spouse !== '' ? $spouse : 'your spouse'), $addon);
                $prompt .= ' ' . $addon;
            }
        }

        return self::replacePlayerRoutePlaceholders($prompt, $partnerName, $npcName);
    }

    /**
     * Build multi-actor scene context for an NPC
     *
     * For group scenes (orgies, threesomes, etc.), builds relationship context
     * for all OTHER participants from this NPC's perspective.
     *
     * @param string $npcName The NPC receiving this prompt (excluded from list)
     * @param array $allActors All actors in the scene (including the NPC)
     * @param bool $isProstitute Whether the NPC is a prostitute
     * @return array ['context' => string, 'lowestAffinity' => int, 'tierPrompt' => string]
     */
    public static function buildMultiActorContext($npcName, $allActors, $isProstitute = false, $promptContext = 'player') {
        // Filter out the NPC receiving this prompt
        $otherActors = array_filter($allActors, function($actor) use ($npcName) {
            return strtolower($actor) !== strtolower($npcName);
        });
        $otherActors = array_values($otherActors); // Re-index

        if (empty($otherActors)) {
            return [
                'context' => '',
                'lowestAffinity' => 0,
                'tierPrompt' => ''
            ];
        }

        // ============================================
        // BUILD NPC PROFILE CONTEXT
        // ============================================
        // Include: spousal status, orientation, preference, arousal
        // This helps the model make informed accept/refuse decisions
        // ============================================
        $profileContext = self::buildNpcProfileContext($npcName, $otherActors);

        // Single partner - simple case
        if (count($otherActors) === 1) {
            $partner = $otherActors[0];
            // PLAYER scene: the single partner IS the player. Look up the canonical 'Player' relationship DIRECTLY
            // (robust against PLAYER_NAME not being resolved when this tier prompt is built - that made the partner
            // affinity read 0 -> tier_neutral -> a hard "stranger, refuse" for EVERY NPC, even bonded ones). NPC-only
            // scenes keep the name-based lookup (the partner is another NPC).
            if (strtolower((string)$promptContext) !== 'npc') {
                $relationship = RelationshipManager::getPlayerRelationship($npcName);
            } else {
                $relationship = self::getRelationshipWith($npcName, $partner);
            }
            $tierPrompt = self::getTierPromptForRelationship($relationship, $isProstitute, $partner, $npcName, $promptContext);
            error_log("[PARTNER_DEBUG] npc={$npcName} resolvedPartner={$partner} tier={$relationship['tier']} promptSnippet=" . substr(str_replace(["\n", "\r"], " ", $tierPrompt), 0, 140));

            $partnerInfo = "Partner: {$partner} ({$relationship['tier']}, affinity: {$relationship['aff']})";

            // Combine profile context with partner info
            $fullContext = $profileContext . "\n" . self::wrapXml('scene_participants', $partnerInfo);

            return [
                'context' => $fullContext,
                'lowestAffinity' => $relationship['aff'],
                'tierPrompt' => self::wrapXml('emotional_state', $tierPrompt)
            ];
        }

        // Multiple partners - group scene
        $lowestAffinity = 100;
        $lowestPartner = null;
        $partnerLines = [];
        $partnerNum = 1;

        foreach ($otherActors as $actor) {
            $relationship = self::getRelationshipWith($npcName, $actor);
            $partnerLines[] = "Partner {$partnerNum} ({$actor}): {$relationship['tier']} (affinity: {$relationship['aff']})";

            // Track lowest affinity - this determines overall mood
            if ($relationship['aff'] < $lowestAffinity) {
                $lowestAffinity = $relationship['aff'];
                $lowestPartner = $actor;
            }
            $partnerNum++;
        }

        // Build context block with XML
        $participantsContent = implode("\n", $partnerLines);

        // Get tier prompt based on LOWEST affinity (most restrictive)
        $routePartner = $lowestPartner;
        $routeAffinity = $lowestAffinity;
        $isNpcPromptContext = (strtolower((string)$promptContext) === 'npc');
        if (!$isNpcPromptContext) {
            $playerName = self::getPlayerRouteName();
            foreach ($otherActors as $actor) {
                if (self::isPlayerRouteName($actor)) {
                    $routePartner = $actor;
                    $routeRelationship = self::getRelationshipWith($npcName, $actor);
                    $routeAffinity = $routeRelationship['aff'];
                    break;
                }
            }
        }
        $routeTier = strtolower(RelationshipManager::getTierLabel($routeAffinity));
        $lowestTier = strtolower(RelationshipManager::getTierLabel($lowestAffinity));
        $prompts = self::loadPromptSettings();

        // Check for marriage/affair scenario with the route partner
        if (self::isMarriageScenario($npcName, $routePartner)) {
            // Partner IS the spouse - use marriage prompts
            $tierPrompt = $isNpcPromptContext
                ? self::getNpcMarriageSpousePrompt($routeTier, $routePartner, $npcName)
                : self::getMarriageSpousePrompt($routeTier, $routePartner);
            $tierPrompt = self::replaceRouteActorPlaceholders($tierPrompt, $routePartner, $promptContext, $npcName);
            error_log("[NSFW_REL] Group marriage scene detected for $npcName with spouse $routePartner (context: {$promptContext})");
        } else if (self::isAffairScenario($npcName, $routePartner)) {
            // Married but partner is NOT spouse - affair prompts
            $spouseName = self::getSpouseName($npcName);
            $tierPrompt = $isNpcPromptContext
                ? self::getNpcAffairTierPrompt($routeTier, $routePartner, $spouseName, $npcName)
                : self::getAffairTierPrompt($routeTier, $routePartner, $spouseName);
            error_log("[NSFW_REL] Group affair detected for $npcName with $routePartner (spouse: $spouseName, context: {$promptContext})");
        } else {
            if ($isNpcPromptContext) {
                $tierPrompt = self::getNpcSceneTierPrompt($routeTier, $routeAffinity, $isProstitute, $routePartner, $npcName);
            } else {
                $promptKey = $isProstitute ? "tier_prost_{$routeTier}" : "tier_{$routeTier}";
                // Get prompt from database - NO HARDCODED FALLBACKS
                $tierPrompt = $prompts[$promptKey] ?? '';
                if (empty($tierPrompt)) {
                    error_log("[NSFW_REL] ERROR: No prompt for '{$promptKey}' - save prompts in NSFW Config UI");
                }
                $tierPrompt = self::replacePlayerRoutePlaceholders($tierPrompt, $routePartner, $npcName);
            }
        }

        $tierPrompt = str_replace('#AFFINITY#', $routeAffinity, $tierPrompt);
        $tierPrompt = str_replace('#TIER#', ucfirst($routeTier), $tierPrompt);

        // Add note about group dynamics (configurable)
        if (count($otherActors) > 1) {
            $groupDynamicsMsg = $prompts['group_dynamics'] ?? '';
            if (empty($groupDynamicsMsg)) {
                error_log("[NSFW_REL] No prompt for 'group_dynamics' - save in NSFW Config UI");
            } else {
                // Full roster so a 3+ way names EVERYONE, not just the lowest-affinity partner.
                $otherParticipantsStr = implode(', ', $otherActors);
                $allParticipantsStr = implode(', ', array_merge([$npcName], $otherActors));
                $groupDynamicsMsg = str_replace('#OTHER_PARTICIPANTS#', $otherParticipantsStr, $groupDynamicsMsg);
                $groupDynamicsMsg = str_replace('#SCENE_PARTICIPANTS#', $allParticipantsStr, $groupDynamicsMsg);
                $groupDynamicsMsg = str_replace('#PRIMARY_PARTNER#', $lowestPartner, $groupDynamicsMsg);
                $groupDynamicsMsg = str_replace('#NPC_NAME#', $npcName, $groupDynamicsMsg);
                $groupDynamicsMsg = str_replace('#TIER#', $lowestTier, $groupDynamicsMsg);
                $groupDynamicsMsg = str_replace('#PLAYER_NAME#', $GLOBALS['PLAYER_NAME'] ?? 'the player', $groupDynamicsMsg);
                $participantsContent .= "\n\n" . $groupDynamicsMsg;
            }
        }

        // Combine profile context with participants
        $fullContext = $profileContext . "\n" . self::wrapXml('scene_participants', $participantsContent);

        return [
            'context' => $fullContext,
            'lowestAffinity' => $lowestAffinity,
            'tierPrompt' => self::wrapXml('emotional_state', $tierPrompt),
            'lowestPartner' => $lowestPartner
        ];
    }

    /**
     * Build NPC profile context for scene decision-making
     *
     * Includes: spousal status, sexual orientation, relationship preference, arousal, relationship type
     * Also checks if partner matches the NPC's orientation
     * Uses configurable prompts from NSFW Framework globals
     *
     * @param string $npcName The NPC's name
     * @param array $partners The scene partners
     * @return string XML-wrapped profile context
     */
    private static function buildNpcProfileContext($npcName, $partners) {
        error_log("[NSFW_REL] ========== buildNpcProfileContext CALLED for $npcName ==========");
        try {
            require_once __DIR__ . "/../../lib/core/npc_master.class.php";
            require_once __DIR__ . "/common.php";
            require_once __DIR__ . "/nsfw_data.php";

            // Get NSFW data from nsfw_npc_data table (NOT core_npc_master.extended_data)
            $extended = NsfwNpcData::get($npcName);
            error_log("[NSFW_REL] extended data for $npcName: " . (empty($extended) ? 'EMPTY' : 'has ' . count($extended) . ' keys'));

            if (empty($extended)) {
                error_log("[NSFW_REL] No profile data for $npcName - returning early");
                return ''; // No profile data
            }

            // Get NPC data for basic info (gender, etc.) - NOT for NSFW data
            $npcManager = new \NpcMaster();
            $npcData = $npcManager->getByName($npcName);
            if (!$npcData) {
                $npcData = $npcManager->getByName(ucfirst(strtolower($npcName)));
            }

            // Load configurable prompts
            $prompts = self::loadPromptSettings();

            // Get profile fields
            $spousalStatus = $extended['spousal_status'] ?? 'single';
            $orientation = $extended['sexual_orientation'] ?? 'bisexual'; // Default to bisexual if not set
            $preference = $extended['relationship_preference'] ?? 'uncommitted';
            $spouseNames = $extended['spouse_names'] ?? '';

            // Get current arousal from intimacy status
            $intimacyStatus = getIntimacyForActor($npcName);
            $arousal = $intimacyStatus['sex_disposal'] ?? 0;

            // Get player info
            $playerName = $GLOBALS['PLAYER_NAME'] ?? 'Player';
            // Get player gender - check multiple sources
            $playerGender = 'male'; // default
            if (isset($GLOBALS['PLAYER_SEX'])) {
                $playerGender = ($GLOBALS['PLAYER_SEX'] == 1) ? 'female' : 'male';
            } elseif (isset($GLOBALS['PLAYER_GENDER'])) {
                $playerGender = strtolower($GLOBALS['PLAYER_GENDER']);
            }

            // Get NPC gender - check multiple sources in priority order:
            // 1. core_npc_master.gender (most reliable)
            // 2. extended_data.sex
            // 3. default to 'unknown'
            $npcGender = 'male'; // default fallback
            if (!empty($npcData['gender'])) {
                // core_npc_master has a 'gender' column with 'male'/'female'
                $npcGender = strtolower($npcData['gender']);
            } elseif (!empty($extended['sex'])) {
                // Check NSFW extended data
                $npcSex = $extended['sex'];
                $npcGender = ($npcSex == 1 || strtolower($npcSex) == 'female') ? 'female' : 'male';
            } elseif (!empty($npcData['sex'])) {
                // Legacy field
                $npcSex = $npcData['sex'];
                $npcGender = ($npcSex == 1 || strtolower($npcSex) == 'female') ? 'female' : 'male';
            }

            // DEBUG: Log gender detection values
            error_log("[NSFW_REL] ORIENTATION DEBUG for $npcName:");
            error_log("[NSFW_REL]   PLAYER_SEX global = " . var_export($GLOBALS['PLAYER_SEX'] ?? 'NOT SET', true));
            error_log("[NSFW_REL]   PLAYER_GENDER global = " . var_export($GLOBALS['PLAYER_GENDER'] ?? 'NOT SET', true));
            error_log("[NSFW_REL]   playerGender = $playerGender");
            error_log("[NSFW_REL]   npcData['gender'] = " . var_export($npcData['gender'] ?? 'NOT SET', true));
            error_log("[NSFW_REL]   npcGender = $npcGender");
            error_log("[NSFW_REL]   orientation = $orientation");

            // Check orientation compatibility with player
            $orientationMatch = self::checkOrientationMatch($orientation, $npcGender, $playerGender);
            error_log("[NSFW_REL]   orientationMatch result = $orientationMatch (npc=$npcGender, player=$playerGender)");

            // Get relationship type from RelationshipManager
            $relationship = RelationshipManager::getPlayerRelationship($npcName);
            $relType = $relationship['type'] ?? 'unknown';

            // Build context lines using configurable prompts
            $lines = [];

            // ===== SPOUSAL STATUS =====
            if ($spousalStatus === 'married' && !empty($spouseNames)) {
                $statusPrompt = $prompts['profile_status_married'] ?? '';
                if (empty($statusPrompt)) {
                    error_log("[NSFW_REL] No prompt for 'profile_status_married' - save in NSFW Config UI");
                } else {
                    $statusPrompt = str_replace('#SPOUSE#', $spouseNames, $statusPrompt);
                    $lines[] = $statusPrompt;
                }
            } elseif ($spousalStatus === 'married') {
                $statusPrompt = $prompts['profile_status_married'] ?? '';
                if (empty($statusPrompt)) {
                    error_log("[NSFW_REL] No prompt for 'profile_status_married' - save in NSFW Config UI");
                } else {
                    $statusPrompt = str_replace('#SPOUSE#', 'someone', $statusPrompt);
                    $lines[] = $statusPrompt;
                }
            } elseif ($spousalStatus === 'widowed') {
                $widowedPrompt = $prompts['profile_status_widowed'] ?? '';
                if (empty($widowedPrompt)) {
                    error_log("[NSFW_REL] No prompt for 'profile_status_widowed' - save in NSFW Config UI");
                } else {
                    $lines[] = $widowedPrompt;
                }
            } else {
                $singlePrompt = $prompts['profile_status_single'] ?? '';
                if (empty($singlePrompt)) {
                    error_log("[NSFW_REL] No prompt for 'profile_status_single' - save in NSFW Config UI");
                } else {
                    $lines[] = $singlePrompt;
                }
            }

            // ===== SEXUAL ORIENTATION =====
            $orientationLabel = ucfirst($orientation);
            if ($orientation === 'asexual') {
                // Asexual = refuse everything
                $orientPrompt = $prompts['profile_orientation_asexual'] ?? '';
                if (empty($orientPrompt)) {
                    error_log("[NSFW_REL] No prompt for 'profile_orientation_asexual' - save in NSFW Config UI");
                    $lines[] = "Sexual orientation: Asexual.";
                } else {
                    $lines[] = $orientPrompt;
                }
            } elseif ($orientationMatch === 'match') {
                $orientPrompt = $prompts['profile_orientation_match'] ?? '';
                if (empty($orientPrompt)) {
                    error_log("[NSFW_REL] No prompt for 'profile_orientation_match' - save in NSFW Config UI");
                    $lines[] = "Sexual orientation: {$orientationLabel}.";
                } else {
                    $orientPrompt = self::replacePlayerRoutePlaceholders($orientPrompt, $playerName);
                    $lines[] = "Sexual orientation: {$orientationLabel}. " . $orientPrompt;
                }
            } elseif ($orientationMatch === 'mismatch') {
                $orientPrompt = $prompts['profile_orientation_mismatch'] ?? '';
                if (empty($orientPrompt)) {
                    error_log("[NSFW_REL] No prompt for 'profile_orientation_mismatch' - save in NSFW Config UI");
                    $lines[] = "Sexual orientation: {$orientationLabel}.";
                } else {
                    $orientPrompt = self::replacePlayerRoutePlaceholders($orientPrompt, $playerName);
                    $lines[] = "Sexual orientation: {$orientationLabel}. " . $orientPrompt;
                }
            } else {
                $lines[] = "Sexual orientation: {$orientationLabel}.";
            }

            // ===== PLAYER BODY TYPE =====
            // Gender and anatomy are separate. The game reports vanilla sex plus OStim/SOS
            // schlong state, so female players are not assigned a penis unless they have one.
            if (function_exists('aiagentNsfwPlayerHasSchlong') && aiagentNsfwPlayerHasSchlong()) {
                $lines[] = "{$playerName} has a cock/penis.";
            } elseif ($playerGender === 'female') {
                $lines[] = "{$playerName} has a pussy/vagina.";
            } else {
                $lines[] = "{$playerName} does not have a schlong.";
            }

            // ===== RELATIONSHIP PREFERENCE =====
            $prefPrompts = [
                'monogamous' => $prompts['profile_pref_monogamous'] ?? '',
                'polyamorous' => $prompts['profile_pref_polyamorous'] ?? '',
                'uncommitted' => $prompts['profile_pref_uncommitted'] ?? '',
                'not_interested' => $prompts['profile_pref_not_interested'] ?? ''
            ];
            $prefPrompt = $prefPrompts[$preference] ?? '';
            if (!empty($prefPrompt)) {
                $lines[] = $prefPrompt;
            } elseif (!empty($preference)) {
                error_log("[NSFW_REL] No prompt for 'profile_pref_{$preference}' - save in NSFW Config UI");
                $lines[] = "Relationship style: {$preference}.";
            }

            // ===== AROUSAL =====
            // Only include arousal prompts if sex_disposal checkbox is enabled in UI
            // Positive = >= 5, Negative = < 0, Neutral = 0-4 (no prompt)
            if (function_exists('isSexDisposalEnabled') && isSexDisposalEnabled()) {
                if ($arousal >= 5) {
                    $arousalPrompt = $prompts['profile_arousal_positive'] ?? '';
                    if (empty($arousalPrompt)) {
                        error_log("[NSFW_REL] No prompt for 'profile_arousal_positive' - save in NSFW Config UI");
                    } else {
                        $lines[] = $arousalPrompt;
                    }
                } elseif ($arousal < 0) {
                    $arousalPrompt = $prompts['profile_arousal_negative'] ?? '';
                    if (empty($arousalPrompt)) {
                        error_log("[NSFW_REL] No prompt for 'profile_arousal_negative' - save in NSFW Config UI");
                    } else {
                        $lines[] = $arousalPrompt;
                    }
                }
                // Neutral (0-4): no arousal prompt added
            }

            // ===== RELATIONSHIP TYPE =====
            if (!empty($relType) && $relType !== 'unknown') {
                $relTypePrompt = $prompts['profile_rel_type'] ?? '';
                if (empty($relTypePrompt)) {
                    error_log("[NSFW_REL] No prompt for 'profile_rel_type' - save in NSFW Config UI");
                    $lines[] = "Your relationship with {$playerName}: {$relType}.";
                } else {
                    $relTypePrompt = self::replacePlayerRoutePlaceholders($relTypePrompt, $playerName);
                    $relTypePrompt = str_replace('#REL_TYPE#', $relType, $relTypePrompt);
                    $lines[] = $relTypePrompt;
                }
            }

            $content = implode("\n", $lines);
            return self::wrapXml('your_profile', $content);

        } catch (Exception $e) {
            error_log("[NSFW_REL] Failed to build profile context for $npcName: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Check if a partner's gender matches the NPC's orientation
     *
     * @param string $orientation NPC's sexual orientation
     * @param string $npcGender NPC's gender (male/female)
     * @param string $partnerGender Partner's gender (male/female)
     * @return string 'match', 'mismatch', or 'unknown'
     */
    private static function checkOrientationMatch($orientation, $npcGender, $partnerGender) {
        $orientation = strtolower($orientation);

        if ($orientation === 'bisexual' || $orientation === 'pansexual') {
            return 'match'; // Attracted to any gender
        }

        if ($orientation === 'asexual') {
            return 'mismatch'; // Not sexually attracted
        }

        if ($orientation === 'heterosexual' || $orientation === 'straight') {
            // Attracted to opposite gender
            if ($npcGender !== $partnerGender) {
                return 'match';
            } else {
                return 'mismatch';
            }
        }

        if ($orientation === 'homosexual' || $orientation === 'gay' || $orientation === 'lesbian') {
            // Attracted to same gender
            if ($npcGender === $partnerGender) {
                return 'match';
            } else {
                return 'mismatch';
            }
        }

        return 'unknown';
    }

    /**
     * Get relationship between NPC and a specific target
     *
     * @param string $npcName The NPC
     * @param string $targetName The target (could be Player or another NPC)
     * @return array ['aff' => int, 'type' => string, 'tier' => string]
     */
    private static function getRelationshipWith($npcName, $targetName) {
        // Check if target is the player. Scene actor names can include runtime suffixes
        // like the player character name, while RelationshipManager stores the player route as "Player".
        if (self::isPlayerRouteName($targetName)) {
            return RelationshipManager::getPlayerRelationship($npcName);
        }

        // NPC to NPC relationship
        return RelationshipManager::getRelationship($npcName, $targetName);
    }

    /**
     * Get tier prompt for a specific relationship
     *
     * @param array $relationship ['aff' => int, 'type' => string, 'tier' => string]
     * @param bool $isProstitute
     * @param string $partnerName
     * @param string|null $npcName The NPC's name (for affair detection)
     * @return string
     */
    private static function getTierPromptForRelationship($relationship, $isProstitute, $partnerName, $npcName = null, $promptContext = 'player') {
        $tier = strtolower($relationship['tier']);
        $isNpcPromptContext = (strtolower((string)$promptContext) === 'npc');

        if (self::shouldUseOpenModePlayerScenePrompt($npcName, $isProstitute, $promptContext)) {
            return self::getOpenModePlayerScenePrompt($npcName, $partnerName, $relationship['aff'] ?? 0, $tier);
        }

        if ($npcName) {
            // MARRIAGE: Partner IS the spouse - use marriage-specific prompts
            if (self::isMarriageScenario($npcName, $partnerName)) {
                $prompt = $isNpcPromptContext
                    ? self::getNpcMarriageSpousePrompt($tier, $partnerName, $npcName)
                    : self::getMarriageSpousePrompt($tier, $partnerName);
                $prompt = self::replaceRouteActorPlaceholders($prompt, $partnerName, $promptContext, $npcName);
                $prompt = str_replace('#AFFINITY#', $relationship['aff'], $prompt);
                $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);
                error_log("[NSFW_REL] Marriage scene detected for $npcName with spouse $partnerName (tier: $tier, context: {$promptContext})");
                return $prompt;
            }

            // AFFAIR: Married but partner is NOT spouse - use affair prompts. Promiscuous-marked
            // NPCs skip the affair-guilt framing and use their tier_slut_<tier> set below.
            if (self::isAffairScenario($npcName, $partnerName)
                && !(function_exists('aiagentNsfwIsSlutNpc') && aiagentNsfwIsSlutNpc($npcName))) {
                $spouseName = self::getSpouseName($npcName);
                $prompt = $isNpcPromptContext
                    ? self::getNpcAffairTierPrompt($tier, $partnerName, $spouseName, $npcName)
                    : self::getAffairTierPrompt($tier, $partnerName, $spouseName);
                $prompt = str_replace('#AFFINITY#', $relationship['aff'], $prompt);
                $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);
                error_log("[NSFW_REL] Affair detected for $npcName with $partnerName (spouse: $spouseName, tier: $tier, context: {$promptContext})");
                return $prompt;
            }
        }

        if ($isNpcPromptContext) {
            $prompt = self::getNpcSceneTierPrompt($tier, $relationship['aff'], $isProstitute, $partnerName, $npcName ?? '');
            $prompt = str_replace('#TYPE#', $relationship['type'], $prompt);
            return $prompt;
        }

        // Regular prompt selection (not married, or partner info not available)
        $prompts = self::loadPromptSettings();
        $promptKey = $isProstitute ? "tier_prost_{$tier}" : "tier_{$tier}";

        // Get prompt from database - regular-NPC safety net for profileless/fresh-DB
        $prompt = $prompts[$promptKey] ?? '';
        // PROMISCUOUS MARK: is_slut NPCs swap to the tier_slut_<tier> scene set.
        $slutTierText = self::getSlutTierPromptText($npcName ?? '', $tier, $isProstitute, $prompts);
        if ($slutTierText !== null) { $prompt = $slutTierText; }
        if (empty($prompt) && !$isProstitute) {
            require_once __DIR__ . "/nsfw_data.php";
            $_defTiers = NsfwData::getDefaultTierPrompts();
            $prompt = $_defTiers[ucfirst($tier)]['regular'] ?? ($_defTiers['Neutral']['regular'] ?? '');
        }
        if (empty($prompt)) {
            error_log("[NSFW_REL] ERROR: No prompt for '{$promptKey}' - save prompts in NSFW Config UI");
        }
        $prompt = self::replacePlayerRoutePlaceholders($prompt, $partnerName, $npcName ?? '');
        $prompt = str_replace('#AFFINITY#', $relationship['aff'], $prompt);
        $prompt = str_replace('#TIER#', ucfirst($tier), $prompt);
        $prompt = str_replace('#TYPE#', $relationship['type'], $prompt);

        return $prompt;
    }

    /**
     * Build complete NSFW context for multi-actor scene
     *
     * @param string $npcName The NPC receiving this prompt
     * @param array $allActors All actors in the scene
     * @param bool $isProstitute
     * @return string Complete context block ready for injection
     */
    public static function buildSceneContext($npcName, $allActors, $isProstitute = false, $promptContext = 'player') {
        $result = self::buildMultiActorContext($npcName, $allActors, $isProstitute, $promptContext);

        if (empty($result['context'])) {
            return '';
        }

        // buildMultiActorContext already returns XML-wrapped content
        $context = $result['context'] . "\n\n";
        $context .= $result['tierPrompt'];
        if (strtolower((string)$promptContext) === 'npc') {
            $context .= "\n\nThis is an NPC-to-NPC scene. Use the relationship context as emotional guidance for dialogue and behavior, but do not choose player-scene AcceptSex or RefuseSex tools on this route. React in character to your scene partner and the current scene.";
        }
        // Player-scene consent is model-driven: NO hardcoded consent cue. The UI-editable tier prompt above + the
        // available RefuseSex tool are sufficient - the model decides whether to engage or refuse.

        return self::wrapXml('intimate_scene', $context);
    }

    // ============================================
    // SLAVE SCENE PROMPTS (Affinity-Based)
    // ============================================
    // These methods return prompts based on simplified affinity tiers:
    //   negative (-100 to -6): Hostile through Cold
    //   neutral (-5 to +5): Neutral
    //   positive (+6 to +100): Acquaintance through Bonded
    //
    // FICTION FRAME INJECTION (Selective):
    //   INCLUDED at escalation points:
    //     - getSlaveTierPrompt() - scene start
    //     - getSlaveSceneCues() - Level 2 engaged
    //     - getSlaveClimaxPrompt() - slave's climax
    //     - getSlaveOwnerClimaxPrompt() - owner's climax
    //   NOT INCLUDED at non-escalation points:
    //     - getSlavePersonality() - Level 1 style
    //     - getSlaveSpeechStyle() - Level 1 style
    //     - getSlavePillowTalkPrompt() - aftermath
    // ============================================

    /**
     * Get the affinity tier category (negative/neutral/positive)
     * Simplified from 11 tiers to 3 categories for slave prompts
     *
     * @param int $affinity The affinity score (-100 to +100)
     * @return string 'negative', 'neutral', or 'positive'
     */
    private static function getAffinityCategory($affinity) {
        if ($affinity <= -31) return 'negative';  // Cold and below
        if ($affinity >= 31) return 'positive';   // Friendly and above
        return 'neutral';                         // Wary..Neutral..Acquaintance (-30..+30)
    }

    /**
     * Get slave personality prompt based on affinity
     * Injected at Level 1 (accepted phase)
     *
     * @param int $affinity The affinity score
     * @param string $ownerName Owner name for placeholder replacement
     * @return string The personality prompt (no fiction frame - non-escalation point)
     */
    public static function getSlavePersonality($affinity, $ownerName = 'Owner') {
        $category = self::getAffinityCategory($affinity);
        $prompts = self::loadPromptSettings();
        $key = "slave_personality_{$category}";

        $prompt = $prompts[$key] ?? self::getDefaultSlavePersonality($category);
        $prompt = self::replacePlayerRoutePlaceholders($prompt, $ownerName);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);

        // No fiction frame here - injected only at escalation points (tier prompt, scene cues, climax)

        return $prompt;
    }

    /**
     * Get slave speech style prompt based on affinity
     * Injected at Level 1 (accepted phase)
     *
     * @param int $affinity The affinity score
     * @param string $ownerName Owner name for placeholder replacement
     * @return string The speech style prompt (no fiction frame - non-escalation point)
     */
    public static function getSlaveSpeechStyle($affinity, $ownerName = 'Owner') {
        $category = self::getAffinityCategory($affinity);
        $prompts = self::loadPromptSettings();
        $key = "slave_speech_{$category}";

        $prompt = $prompts[$key] ?? self::getDefaultSlaveSpeechStyle($category);
        $prompt = self::replacePlayerRoutePlaceholders($prompt, $ownerName);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);

        // No fiction frame here - injected only at escalation points (tier prompt, scene cues, climax)

        return $prompt;
    }

    /**
     * Get slave scene cues prompt based on affinity
     * Injected at Level 2 (engaged phase)
     *
     * @param int $affinity The affinity score
     * @param string $ownerName Owner name for placeholder replacement
     * @return string The scene cues prompt with fiction frame prepended
     */
    public static function getSlaveSceneCues($affinity, $ownerName = 'Owner') {
        $category = self::getAffinityCategory($affinity);
        $prompts = self::loadPromptSettings();
        $key = "slave_scene_cues_{$category}";

        // Per-NPC Scene Cues field overrides the global affinity cue when set
        $prompt = self::getPerNpcSlaveCue('scene_cues');
        if ($prompt === '') {
            $prompt = $prompts[$key] ?? self::getDefaultSlaveSceneCues($category);
        }
        $prompt = self::replacePlayerRoutePlaceholders($prompt, $ownerName);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);

        // Prepend fiction frame
        $fictionFrame = self::getSlaveryFictionFrame();
        if (!empty($fictionFrame)) {
            $prompt = $fictionFrame . "\n\n" . $prompt;
        }

        return $prompt;
    }

    /**
     * Get slave climax prompt based on affinity
     * Used during climax/orgasm events
     *
     * @param int $affinity The affinity score
     * @param string $ownerName Owner name for placeholder replacement
     * @return string The climax prompt with fiction frame prepended
     */
    public static function getSlaveClimaxPrompt($affinity, $ownerName = 'Owner') {
        $category = self::getAffinityCategory($affinity);
        $prompts = self::loadPromptSettings();
        $key = "slave_climax_{$category}";

        // Per-NPC Climax: the tier matching this slave's affinity (pos/neutral/neg) overrides the global cue
        $prompt = self::getPerNpcSlaveCue("slave_climax_{$category}");
        if ($prompt === '') {
            $prompt = $prompts[$key] ?? self::getDefaultSlaveClimax($category);
        }
        $prompt = self::replacePlayerRoutePlaceholders($prompt, $ownerName);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);

        // Prepend fiction frame
        $fictionFrame = self::getSlaveryFictionFrame();
        if (!empty($fictionFrame)) {
            $prompt = $fictionFrame . "\n\n" . $prompt;
        }

        return $prompt;
    }

    /**
     * Get slave pillow talk prompt based on affinity
     * Used after scene ends (chatnf_sl_end)
     *
     * @param int $affinity The affinity score
     * @param string $ownerName Owner name for placeholder replacement
     * @return string The pillow talk prompt (no fiction frame - non-escalation point)
     */
    public static function getSlavePillowTalkPrompt($affinity, $ownerName = 'Owner') {
        $category = self::getAffinityCategory($affinity);
        $prompts = self::loadPromptSettings();
        $key = "slave_pillow_talk_{$category}";

        // Per-NPC Aftermath field overrides the global affinity cue when set
        $prompt = self::getPerNpcSlaveCue('aftermath');
        if ($prompt === '') {
            $prompt = $prompts[$key] ?? self::getDefaultSlavePillowTalk($category);
        }
        $prompt = self::replacePlayerRoutePlaceholders($prompt, $ownerName);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);

        // Replace #TIER# with the actual tier label
        $tierLabel = strtolower(RelationshipManager::getTierLabel($affinity));
        $prompt = str_replace('#TIER#', $tierLabel, $prompt);

        // No fiction frame here - injected only at escalation points (tier prompt, scene cues, climax)

        return $prompt;
    }

    /**
     * Get slave owner climax reaction prompt based on affinity
     * Used when the owner/master reaches climax
     *
     * @param int $affinity The affinity score
     * @param string $ownerName Owner name for placeholder replacement
     * @return string The owner climax reaction prompt (with fiction frame - escalation point)
     */
    public static function getSlaveOwnerClimaxPrompt($affinity, $ownerName = 'Owner') {
        $category = self::getAffinityCategory($affinity);
        $prompts = self::loadPromptSettings();
        $key = "slave_owner_climax_{$category}";

        $prompt = $prompts[$key] ?? self::getDefaultSlaveOwnerClimax($category);
        $prompt = self::replacePlayerRoutePlaceholders($prompt, $ownerName);
        $prompt = str_replace('#AFFINITY#', $affinity, $prompt);

        // Prepend fiction frame for model safety context (escalation point)
        $fictionFrame = self::getSlaveryFictionFrame();
        if (!empty($fictionFrame)) {
            $prompt = $fictionFrame . "\n\n" . $prompt;
        }

        return $prompt;
    }

    // ============================================
    // DEFAULT SLAVE SCENE PROMPTS
    // ============================================

    private static function getDefaultSlavePersonality($category) {
        $defaults = [
            'negative' => 'You are a slave who resents your bondage. You comply because you must, but your eyes betray your hatred. Every touch from #PLAYER_NAME# makes your skin crawl. Internal resistance, external compliance.',
            'neutral' => 'You are a slave who has accepted your role. You serve #PLAYER_NAME# without emotion. This is survival. You have learned to disconnect from what happens to your body.',
            'positive' => 'You are a slave who has grown fond of #PLAYER_NAME#. Despite your bondage, you find yourself wanting to please them. Your service comes from something approaching genuine affection.',
        ];
        return $defaults[$category] ?? $defaults['neutral'];
    }

    private static function getDefaultSlaveSpeechStyle($category) {
        // Player default slave speak style
        $generic = 'You serve your master, #PLAYER_NAME#, you know how you feel about them. You understand your position, and do what is necessary.';
        return $generic;
    }

    private static function getDefaultSlaveSceneCues($category) {
        // Player default slave scene cues
        $generic = 'You should pick up on the cues from your master. Your responses should be based upon how you feel about your master and what kind of relationship you have with them.';
        return $generic;
    }

    private static function getDefaultSlaveClimax($category) {
        // Player defaults: three tiers by the slave's affinity toward their master
        $defaults = [
            'positive' => 'You and your master have developed quite a positive relationship. You believe that if you continue to work hard and pleasure Master #PLAYER_NAME# that maybe one day he can see you as something more than a slave. You have developed sort of a Stockholm syndrome for them. EXAMPLE SPEECH OUTPUT: "Oh yes Master! Own me! I am your property to do what you will."',
            'neutral' => 'Understand the relationship with your master. You have to ask yourself how you feel about them. How you feel about them should determine if it was a pleasurable experience or it was a violation of your soul. EXAMPLE SPEECH FORMAT: "Master, it is good you have pleasured yourself, I am ready for my next duty. What would you have me do?"',
            'negative' => 'You DESPISE your master, but you must submit because you are #PLAYER_NAME#\'s slave. You offer no excitement in their orgasm. EXAMPLE SPEECH FORMAT: "Are you finished, Master #PLAYER_NAME#, am I free to go back to my duties?"',
        ];
        return $defaults[$category] ?? $defaults['neutral'];
    }

    /**
     * Get default slave owner climax reaction prompt
     * Used when the owner/master reaches climax
     */
    private static function getDefaultSlaveOwnerClimax($category) {
        $generic = 'How do you feel about your master? Is his orgasm something that you wanted, or is it something that you have to fake, bite your tongue, and take? Check your relationship affinity to determine your appropriate response.';
        return $generic;
    }

    private static function getDefaultSlavePillowTalk($category) {
        // Player default slave aftermath
        $generic = 'Check your relationship affinity. How do you feel about your master? Based upon what you know was this a pleasurable experience? Or was it a violation that you have to hold on the inside? Respond accordingly.';
        return $generic;
    }
}
