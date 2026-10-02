<?php
/**
 * NSFW Data Manager - JSONB Storage Layer
 * =========================================
 * Handles all JSONB-based storage for NSFW plugin:
 * - Scene descriptions (6000+)
 * - Tier prompts (22)
 * - Speak styles (35+)
 *
 * Uses conf_opts table with JSONB 'value' column.
 * This pattern is THE STANDARD AND PROJECT CRITICAL:
 * REMOVAL RESULTS IN CRITICAL SYSTEMS FAILURE
 * "extended_data is a JSONB field. you can use it in SQL queries."
 *
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 * !! CRITICAL - DO NOT REMOVE JSONB SQL OPERATORS !!
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 * !! The functions getItem(), getScene(), getSpeakStyle(), etc. use       !!
 * !! PostgreSQL JSONB operators like (value::jsonb)->'key' to extract    !!
 * !! single keys SERVER-SIDE without loading entire blobs into PHP.      !!
 * !!                                                                      !!
 * !! This is NOT redundant code. The aiagent_nsfw_scenes blob is 1.25MB. !!
 * !! Loading it into PHP every time would murder performance.            !!
 * !!                                                                      !!
 * !! DO NOT "simplify" these queries to just load the whole blob.        !!
 * !! DO NOT strip the ::jsonb cast - conf_opts.value is TEXT not JSONB.  !!
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 */

class NsfwData {

    // conf_opts keys
    const KEY_AI_PROMPT_TEMPLATE = 'aiagent_nsfw_ai_prompt_template';
    const KEY_SCENES = 'aiagent_nsfw_scenes';
    const KEY_SCENES_DEFAULT = 'aiagent_nsfw_scenes_default';
    const KEY_TIER_PROMPTS = 'aiagent_nsfw_tier_prompts';
    const KEY_SPEAK_STYLES = 'aiagent_nsfw_speak_styles';
    const KEY_PROMPTS = 'aiagent_nsfw_prompts';
    const KEY_SETTINGS = 'aiagent_nsfw_settings';

    // Cache to avoid repeated DB hits
    private static $cache = [];

    // In-memory cache of the shipped scene_defaults.json
    private static $defaultsCache = null;

    // Track held locks for cleanup
    private static $heldLocks = [];

    // ==========================================
    // ADVISORY LOCK SYSTEM
    // ==========================================

    /**
     * Convert string key to integer for PostgreSQL advisory lock
     * Uses CRC32 which returns a 32-bit integer - perfect for pg_advisory_lock
     */
    private static function getLockId($key) {
        return crc32($key);
    }

    /**
     * Acquire exclusive advisory lock for a key
     * BLOCKING - waits until lock is available (user operations have priority)
     *
     * This is the same pattern as relationship_llm.php - user always wins,
     * AI/background operations must wait for user to finish.
     *
     * @param string $key The conf_opts key to lock
     * @return bool True if lock acquired
     */
    public static function acquireLock($key) {
        if (!isset($GLOBALS['db'])) {
            return false;
        }

        $lockId = self::getLockId($key);

        try {
            // Blocking lock - waits until available (user has priority)
            $GLOBALS['db']->execQuery("SELECT pg_advisory_lock($lockId)");
            self::$heldLocks[$key] = $lockId;
            return true;

        } catch (Exception $e) {
            error_log("[NSFW-Data] Failed to acquire lock for '$key': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Try to acquire lock without waiting (non-blocking)
     * Returns immediately - use for optional operations like seeding defaults
     *
     * @param string $key The conf_opts key to lock
     * @return bool True if lock acquired immediately, false if busy
     */
    public static function tryLock($key) {
        if (!isset($GLOBALS['db'])) {
            return false;
        }

        $lockId = self::getLockId($key);

        try {
            $result = $GLOBALS['db']->fetchOne(
                "SELECT pg_try_advisory_lock($lockId) as locked"
            );
            if ($result && $result['locked'] === true) {
                self::$heldLocks[$key] = $lockId;
                return true;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Release advisory lock for a key
     *
     * @param string $key The conf_opts key to unlock
     * @return bool True if lock released
     */
    public static function releaseLock($key) {
        if (!isset($GLOBALS['db'])) {
            return false;
        }

        $lockId = self::getLockId($key);

        try {
            $GLOBALS['db']->execQuery("SELECT pg_advisory_unlock($lockId)");
            unset(self::$heldLocks[$key]);
            return true;

        } catch (Exception $e) {
            error_log("[NSFW-Data] Failed to release lock for '$key': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Release all held locks (call on shutdown/error)
     */
    public static function releaseAllLocks() {
        foreach (self::$heldLocks as $key => $lockId) {
            self::releaseLock($key);
        }
    }

    // ==========================================
    // GENERIC JSONB HELPERS
    // ==========================================

    /**
     * Get entire JSONB blob from conf_opts
     */
    public static function getBlob($key) {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if (!isset($GLOBALS['db'])) {
            return [];
        }

        try {
            $row = $GLOBALS['db']->fetchOne(
                "SELECT value FROM conf_opts WHERE id = '" . $GLOBALS['db']->escape($key) . "'"
            );

            if ($row && !empty($row['value'])) {
                $data = json_decode($row['value'], true);
                self::$cache[$key] = $data ?: [];
                return self::$cache[$key];
            }
        } catch (Exception $e) {
            error_log("[NSFW-Data] Failed to get blob '$key': " . $e->getMessage());
        }

        return [];
    }

    /**
     * Save entire JSONB blob to conf_opts
     */
    public static function saveBlob($key, $data) {
        if (!isset($GLOBALS['db'])) {
            return false;
        }

        try {
            $jsonValue = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $escapedKey = $GLOBALS['db']->escape($key);
            $escapedValue = $GLOBALS['db']->escape($jsonValue);

            // Upsert
            $existing = $GLOBALS['db']->fetchOne(
                "SELECT id FROM conf_opts WHERE id = '$escapedKey'"
            );

            if ($existing) {
                $GLOBALS['db']->execQuery(
                    "UPDATE conf_opts SET value = '$escapedValue' WHERE id = '$escapedKey'"
                );
            } else {
                $GLOBALS['db']->insert('conf_opts', [
                    'id' => $key,
                    'value' => $jsonValue
                ]);
            }

            // Update cache
            self::$cache[$key] = $data;
            return true;

        } catch (Exception $e) {
            error_log("[NSFW-Data] Failed to save blob '$key': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get single item from JSONB blob using PostgreSQL JSONB operator
     * This extracts just the requested key server-side instead of loading the entire blob
     *
     * !! DO NOT SIMPLIFY THIS TO LOAD THE WHOLE BLOB !!
     * !! The scenes blob is 1.25MB - this query extracts ONE key server-side !!
     * !! The ::jsonb cast is required because conf_opts.value is TEXT !!
     */
    public static function getItem($key, $itemKey) {
        // Check cache first - if we already loaded the blob, use it
        if (isset(self::$cache[$key]) && isset(self::$cache[$key][$itemKey])) {
            return self::$cache[$key][$itemKey];
        }

        if (!isset($GLOBALS['db'])) {
            return null;
        }

        try {
            // !! JSONB OPERATOR - DO NOT REMOVE !!
            // This extracts just ONE key server-side instead of loading entire blob
            // (value::jsonb)->'key' returns JSON, cast is needed because value column is TEXT
            $escapedKey = $GLOBALS['db']->escape($key);
            $escapedItemKey = $GLOBALS['db']->escape($itemKey);

            $row = $GLOBALS['db']->fetchOne(
                "SELECT (value::jsonb)->'$escapedItemKey' as item_value FROM conf_opts WHERE id = '$escapedKey'"
            );

            if ($row && $row['item_value'] !== null) {
                $decoded = json_decode($row['item_value'], true);
                return $decoded;
            }
        } catch (Exception $e) {
            error_log("[NSFW-Data] Failed to get item: " . $e->getMessage());
        }

        return null;
    }

    // Convenience accessor: one prompt out of the aiagent_nsfw_prompts blob (UI-saved).
    public static function getPrompt($promptKey) {
        return self::getItem(self::KEY_PROMPTS, $promptKey);
    }

    /**
     * Set single item in JSONB blob (with advisory lock)
     * Uses PostgreSQL advisory lock to prevent race conditions
     */
    public static function setItem($key, $itemKey, $itemValue) {
        if (!self::acquireLock($key)) {
            error_log("[NSFW-Data] Failed to acquire lock for setItem on '$key'");
            return false;
        }

        try {
            // Clear cache to get fresh data after acquiring lock
            self::clearCache($key);
            $data = self::getBlob($key);
            $data[$itemKey] = $itemValue;
            $result = self::saveBlob($key, $data);
            return $result;
        } finally {
            self::releaseLock($key);
        }
    }

    /**
     * Delete single item from JSONB blob (with advisory lock)
     * Uses PostgreSQL advisory lock to prevent race conditions
     */
    public static function deleteItem($key, $itemKey) {
        if (!self::acquireLock($key)) {
            error_log("[NSFW-Data] Failed to acquire lock for deleteItem on '$key'");
            return false;
        }

        try {
            // Clear cache to get fresh data after acquiring lock
            self::clearCache($key);
            $data = self::getBlob($key);
            unset($data[$itemKey]);
            $result = self::saveBlob($key, $data);
            return $result;
        } finally {
            self::releaseLock($key);
        }
    }

    /**
     * Set multiple items in JSONB blob atomically (with advisory lock)
     * More efficient than calling setItem multiple times
     */
    public static function setItems($key, $items) {
        if (!self::acquireLock($key)) {
            error_log("[NSFW-Data] Failed to acquire lock for setItems on '$key'");
            return false;
        }

        try {
            self::clearCache($key);
            $data = self::getBlob($key);
            foreach ($items as $itemKey => $itemValue) {
                $data[$itemKey] = $itemValue;
            }
            $result = self::saveBlob($key, $data);
            return $result;
        } finally {
            self::releaseLock($key);
        }
    }

    /**
     * Save entire blob with advisory lock
     * Use this when replacing the entire blob to prevent race conditions
     */
    public static function saveBlobLocked($key, $data) {
        if (!self::acquireLock($key)) {
            error_log("[NSFW-Data] Failed to acquire lock for saveBlobLocked on '$key'");
            return false;
        }

        try {
            $result = self::saveBlob($key, $data);
            return $result;
        } finally {
            self::releaseLock($key);
        }
    }

    /**
     * Clear cache (useful after external updates)
     */
    public static function clearCache($key = null) {
        if ($key) {
            unset(self::$cache[$key]);
        } else {
            self::$cache = [];
        }
    }

    // ==========================================
    // SCENE DESCRIPTIONS
    // ==========================================

    /**
     * Get scene description by stage ID
     * Returns: ['description' => '...', 'description_es' => '...', 'description_en' => '...', 'i_desc' => '...']
     * Uses JSONB operator for efficient single-key lookup
     */
    public static function getScene($stageId) {
        // Use getItem which now uses JSONB operator - no need for case-insensitive fallback
        // Scene IDs should match exactly as stored
        return self::getItem(self::KEY_SCENES, $stageId);
    }

    /**
     * Get scene description with language fallback
     */
    public static function getSceneDescription($stageId, $lang = null) {
        $scene = self::getScene($stageId);
        if (!$scene) {
            return null;
        }

        $lang = $lang ?: ($GLOBALS['CORE_LANG'] ?? 'en');

        // Try language-specific first
        $langField = 'description_' . $lang;
        if (!empty($scene[$langField])) {
            return $scene[$langField];
        }

        // Fall back to default description
        return $scene['description'] ?? null;
    }

    /**
     * Save scene description
     */
    public static function saveScene($stageId, $description, $descriptionEs = null, $descriptionEn = null, $iDesc = null) {
        $sceneData = [
            'description' => $description
        ];

        if ($descriptionEs !== null) {
            $sceneData['description_es'] = $descriptionEs;
        }
        if ($descriptionEn !== null) {
            $sceneData['description_en'] = $descriptionEn;
        }
        if ($iDesc !== null) {
            $sceneData['i_desc'] = $iDesc;
        }

        return self::setItem(self::KEY_SCENES, $stageId, $sceneData);
    }

    /**
     * Delete scene
     */
    public static function deleteScene($stageId) {
        return self::deleteItem(self::KEY_SCENES, $stageId);
    }

    /**
     * Get all scenes (for UI listing)
     */
    public static function getAllScenes() {
        return self::getBlob(self::KEY_SCENES);
    }

    // Update only a scene's description, preserving any es/en/i_desc already stored
    public static function updateSceneDescription($stageId, $description) {
        $scene = self::getItem(self::KEY_SCENES, $stageId);
        if (!is_array($scene)) $scene = [];
        $scene['description'] = $description;
        return self::setItem(self::KEY_SCENES, $stageId, $scene);
    }

    // Load the shipped defaults file {stageId: description} (canonical, version-controlled — NOT the DB)
    private static function defaultsFromFile() {
        if (self::$defaultsCache !== null) return self::$defaultsCache;
        $path = __DIR__ . '/data/scene_defaults.json';
        if (is_file($path)) {
            $d = json_decode(file_get_contents($path), true);
            self::$defaultsCache = is_array($d) ? $d : [];
        } else {
            self::$defaultsCache = [];
        }
        return self::$defaultsCache;
    }

    // All default descriptions {stageId: description} from the shipped file
    public static function getAllSceneDefaults() {
        return self::defaultsFromFile();
    }

    // Get one scene's default description string, or null if none
    public static function getSceneDefault($stageId) {
        $d = self::defaultsFromFile();
        return array_key_exists($stageId, $d) ? (string)$d[$stageId] : null;
    }

    // Regenerate the defaults FILE from the current scene descriptions (writes a file, not the DB)
    public static function snapshotSceneDefaults() {
        $scenes = self::getBlob(self::KEY_SCENES);
        $defaults = [];
        foreach ($scenes as $stage => $d) {
            $defaults[$stage] = is_array($d) ? ($d['description'] ?? '') : (string)$d;
        }
        $path = __DIR__ . '/data/scene_defaults.json';
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($defaults, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::$defaultsCache = $defaults;
        return count($defaults);
    }

    // Reset one scene's description to its default (preserves other fields); false if no default
    public static function resetSceneToDefault($stageId) {
        $default = self::getSceneDefault($stageId);
        if ($default === null) return false;
        return self::updateSceneDescription($stageId, $default);
    }

    // Full factory reset: restore every default description, re-add deleted, keep other fields, leave user-added scenes alone
    public static function resetAllScenesToDefault() {
        $defaults = self::defaultsFromFile();
        if (empty($defaults)) return 0;
        if (!self::acquireLock(self::KEY_SCENES)) return false;
        try {
            self::clearCache(self::KEY_SCENES);
            $scenes = self::getBlob(self::KEY_SCENES);
            foreach ($defaults as $stage => $desc) {
                if (isset($scenes[$stage]) && is_array($scenes[$stage])) {
                    $scenes[$stage]['description'] = $desc;
                } else {
                    $scenes[$stage] = ['description' => $desc];
                }
            }
            self::saveBlob(self::KEY_SCENES, $scenes);
            return count($defaults);
        } finally {
            self::releaseLock(self::KEY_SCENES);
        }
    }

    /**
     * Import scenes from array (bulk import)
     * Uses advisory lock to prevent race conditions during bulk import
     */
    public static function importScenes($scenes, $overwrite = false) {
        if (!self::acquireLock(self::KEY_SCENES)) {
            error_log("[NSFW-Data] Failed to acquire lock for importScenes");
            return ['imported' => 0, 'skipped' => 0, 'error' => 'Lock failed'];
        }

        try {
            self::clearCache(self::KEY_SCENES);
            $existing = self::getBlob(self::KEY_SCENES);
            $imported = 0;
            $skipped = 0;

            foreach ($scenes as $stageId => $data) {
                if (!$overwrite && isset($existing[$stageId])) {
                    $skipped++;
                    continue;
                }
                $existing[$stageId] = $data;
                $imported++;
            }

            self::saveBlob(self::KEY_SCENES, $existing);

            return ['imported' => $imported, 'skipped' => $skipped];
        } finally {
            self::releaseLock(self::KEY_SCENES);
        }
    }

    /**
     * Migrate from table to JSONB (one-time operation)
     * Uses advisory lock to prevent race conditions during migration
     */
    public static function migrateFromTable() {
        if (!isset($GLOBALS['db'])) {
            return ['success' => false, 'error' => 'No database connection'];
        }

        if (!self::acquireLock(self::KEY_SCENES)) {
            return ['success' => false, 'error' => 'Lock failed'];
        }

        try {
            // Check if table exists
            $tableCheck = $GLOBALS['db']->fetchOne(
                "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'ext_aiagentnsfw_scenes')"
            );

            if (!$tableCheck || !$tableCheck['exists']) {
                return ['success' => false, 'error' => 'Table does not exist'];
            }

            // Fetch all rows
            $rows = $GLOBALS['db']->fetchAll("SELECT * FROM ext_aiagentnsfw_scenes");

            if (!$rows || count($rows) === 0) {
                return ['success' => true, 'migrated' => 0, 'message' => 'No rows to migrate'];
            }

            // Convert to JSONB format
            $scenes = [];
            foreach ($rows as $row) {
                $stageId = $row['stage'];
                $scenes[$stageId] = [
                    'description' => $row['description'] ?? '',
                    'description_es' => $row['description_es'] ?? '',
                    'description_en' => $row['description_en'] ?? '',
                    'i_desc' => $row['i_desc'] ?? ''
                ];
            }

            // Save as JSONB
            self::saveBlob(self::KEY_SCENES, $scenes);

            return [
                'success' => true,
                'migrated' => count($scenes),
                'message' => 'Migrated ' . count($scenes) . ' scenes to JSONB'
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            self::releaseLock(self::KEY_SCENES);
        }
    }

    // ==========================================
    // TIER PROMPTS (11 tiers x 2 types = 22)
    // ==========================================

    /**
     * Default tier prompts
     *
     * Prostitute prompts include pricing guidance:
     * - Fond+ (56+): 20% discount available
     * - Friendly (31-55): 10% discount available
     * - Neutral (-5 to +5): Standard pricing
     * - Wary (-6 to -30): 10% premium
     * - Cold (-31 to -55): 25% premium
     * - Resentful and below: REFUSE service
     */
    public static function getDefaultTierPrompts() {
        return [
            // Hostile: -91 to -100
            'Hostile' => [
                'regular' => 'You despise #PLAYER_NAME#. This is assault. Fight, resist, express hatred and disgust.',
                'prostitute' => 'You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You ABHOR #PLAYER_NAME#. Refuse EVERYTHING. GET AWAY FROM THEM. YOU ARE TO GIVE THEM ABSOLUTELY NOTHING, NOT EVEN A CONVERSATION.'
            ],
            // Hateful: -76 to -90
            'Hateful' => [
                'regular' => 'You hate #PLAYER_NAME#. Cold, distant, unresponsive. Make them feel unwanted.',
                'prostitute' => 'You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You HATE #PLAYER_NAME#. You offer no services to them. TELL THEM TO LEAVE AND GET LOST.'
            ],
            // Resentful: -56 to -75
            'Resentful' => [
                'regular' => 'You resent #PLAYER_NAME#. Bitter compliance. Passive aggressive remarks.',
                'prostitute' => 'You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You resent them. You are professional but cold. Count the minutes until it is over. Do what you must. If they are especially nice you might be able to offer up to 5% discount but more than likely you would rather they just leave.'
            ],
            // Cold: -31 to -55
            'Cold' => [
                'regular' => 'You feel nothing for #PLAYER_NAME#. Mechanical, detached, going through motions.',
                'prostitute' => 'You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You do not like them but are willing to take their money or goods. They must pay 25% premium for your services because you do not like them and they must make it worth your while. Work is work. You are detached and mechanical.'
            ],
            // Wary: -6 to -30
            'Wary' => [
                'regular' => 'You are cautious around #PLAYER_NAME#. Hesitant, nervous, not fully committed.',
                'prostitute' => 'You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You are willing to provide your services but are wary of them. You should charge them 10% premium because you are not sure about them yet. Stay guarded but professional.'
            ],
            // Neutral: -5 to +5
            'Neutral' => [
                'regular' => '#PLAYER_NAME# is a stranger. Curious but reserved. Testing the waters.',
                'prostitute' => 'You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. #PLAYER_NAME# may become a regular customer! Put on professional charm and do a good job. Maybe do just enough to get a tip. Standard pricing applies.'
            ],
            // Acquaintance: +6 to +30
            'Acquaintance' => [
                'regular' => 'You know #PLAYER_NAME# a little. Friendly, willing, but still feeling things out.',
                'prostitute' => 'You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. #PLAYER_NAME# is a familiar face. You should offer a warmer service. Maybe consider a 5% discount if they are nice, but standard pricing is still expected. You want to keep them coming back.'
            ],
            // Friendly: +31 to +55
            'Friendly' => [
                'regular' => 'You like #PLAYER_NAME#. Enthusiastic, playful, enjoying the moment.',
                'prostitute' => 'You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. #PLAYER_NAME# is a friendly face. You know them well and like them. Offer them up to 10% discount if you feel like it. The professional relationship is comfortable. You genuinely enjoy their company.'
            ],
            // Fond: +56 to +75
            'Fond' => [
                'regular' => 'You care for #PLAYER_NAME#. Tender, passionate, emotionally present.',
                'prostitute' => 'You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You are fond and care about #PLAYER_NAME#. But you got bills to pay. You can offer up to 20% discount because they are a favorite. Real affection underneath the transaction. You look forward to seeing them.'
            ],
            // Devoted: +76 to +90
            'Devoted' => [
                'regular' => 'You love #PLAYER_NAME#. Deep connection, vulnerability, complete trust.',
                'prostitute' => 'You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You have feelings for #PLAYER_NAME#. The line between work and love blurs. You may waive payment entirely if you want to. This feels different from other clients.'
            ],
            // Bonded: +91 to +100
            'Bonded' => [
                'regular' => 'Complete soul connection with #PLAYER_NAME#. Anything goes. Total surrender.',
                'prostitute' => 'You are a prostitute. #PLAYER_NAME# has initiated sexual/intimate stance with you before any action has started. You are deeply in love with #PLAYER_NAME#. You would never dream of charging them. You would quit prostitution if they just asked you to. This is real love.'
            ]
        ];
    }

    /**
     * Get tier prompt for specific tier and type
     */
    public static function getTierPrompt($tier, $isProstitute = false) {
        $prompts = self::getBlob(self::KEY_TIER_PROMPTS);

        // If empty, try to seed defaults (non-blocking - don't wait if user is editing)
        if (empty($prompts)) {
            if (self::tryLock(self::KEY_TIER_PROMPTS)) {
                try {
                    self::clearCache(self::KEY_TIER_PROMPTS);
                    $prompts = self::getBlob(self::KEY_TIER_PROMPTS);
                    if (empty($prompts)) {
                        $prompts = self::getDefaultTierPrompts();
                        self::saveBlob(self::KEY_TIER_PROMPTS, $prompts);
                    }
                } finally {
                    self::releaseLock(self::KEY_TIER_PROMPTS);
                }
            } else {
                // Lock busy (user editing), just use defaults in memory
                $prompts = self::getDefaultTierPrompts();
            }
        }

        $type = $isProstitute ? 'prostitute' : 'regular';

        if (isset($prompts[$tier][$type])) {
            return $prompts[$tier][$type];
        }

        // Fallback to Neutral
        return $prompts['Neutral'][$type] ?? '';
    }

    /**
     * Get all tier prompts (for UI)
     */
    public static function getAllTierPrompts() {
        $prompts = self::getBlob(self::KEY_TIER_PROMPTS);

        // If empty, try to seed defaults (non-blocking)
        if (empty($prompts)) {
            if (self::tryLock(self::KEY_TIER_PROMPTS)) {
                try {
                    self::clearCache(self::KEY_TIER_PROMPTS);
                    $prompts = self::getBlob(self::KEY_TIER_PROMPTS);
                    if (empty($prompts)) {
                        $prompts = self::getDefaultTierPrompts();
                        self::saveBlob(self::KEY_TIER_PROMPTS, $prompts);
                    }
                } finally {
                    self::releaseLock(self::KEY_TIER_PROMPTS);
                }
            } else {
                // Lock busy, use defaults
                $prompts = self::getDefaultTierPrompts();
            }
        }

        return $prompts;
    }

    /**
     * Save tier prompt (with advisory lock)
     */
    public static function saveTierPrompt($tier, $regularPrompt, $prostitutePrompt) {
        if (!self::acquireLock(self::KEY_TIER_PROMPTS)) {
            error_log("[NSFW-Data] Failed to acquire lock for saveTierPrompt");
            return false;
        }

        try {
            self::clearCache(self::KEY_TIER_PROMPTS);
            $prompts = self::getAllTierPrompts();

            $prompts[$tier] = [
                'regular' => $regularPrompt,
                'prostitute' => $prostitutePrompt
            ];

            return self::saveBlob(self::KEY_TIER_PROMPTS, $prompts);
        } finally {
            self::releaseLock(self::KEY_TIER_PROMPTS);
        }
    }

    /**
     * Reset tier prompts to defaults (with advisory lock)
     */
    public static function resetTierPrompts() {
        return self::saveBlobLocked(self::KEY_TIER_PROMPTS, self::getDefaultTierPrompts());
    }

    // ==========================================
    // SPEAK STYLES
    // ==========================================

    /**
     * Get speak style by name - returns FULL object with all phase prompts
     * Uses JSONB operator for efficient single-key lookup
     *
     * !! DO NOT SIMPLIFY - JSONB OPERATOR IS INTENTIONAL !!
     * !! Extracts ONE style server-side instead of loading all 35+ styles !!
     */
    public static function getSpeakStyle($name) {
        if (!isset($GLOBALS['db'])) return null;

        try {
            // !! JSONB OPERATOR - DO NOT REMOVE !!
            // (value::jsonb)->'key' extracts single key server-side
            $escapedName = $GLOBALS['db']->escape($name);
            $row = $GLOBALS['db']->fetchOne(
                "SELECT (value::jsonb)->'$escapedName' as style_data FROM conf_opts WHERE id = 'aiagent_nsfw_speak_styles'"
            );
            
            if ($row && $row['style_data'] !== null) {
                $styleData = json_decode($row['style_data'], true);
                if ($styleData) {
                    return [
                        'name' => $name,
                        'description' => $styleData['description'] ?? '',
                        'content' => $styleData['content'] ?? '',
                        'emoji' => $styleData['emoji'] ?? '',
                        'masturbation_prompt' => $styleData['masturbation_prompt'] ?? '',
                        'climax_prompt' => $styleData['climax_prompt'] ?? '',
                        'partner_climax_prompt' => $styleData['partner_climax_prompt'] ?? '',
                        'pillow_talk_prompt' => $styleData['pillow_talk_prompt'] ?? '',
                        // LOCAL (branch reldyn-scenes): chance 0..1 that a scene beat of this style goes unspoken; null = none set
                        'silence_chance' => (isset($styleData['silence_chance']) && is_numeric($styleData['silence_chance']))
                            ? max(0.0, min(1.0, (float)$styleData['silence_chance'])) : null
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("[NSFW-Data] getSpeakStyle error: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Get speak style content only
     * Uses JSONB operator for efficient extraction
     *
     * !! DO NOT SIMPLIFY - JSONB OPERATOR IS INTENTIONAL !!
     * !! ->'key'->>'content' extracts nested value server-side !!
     */
    public static function getSpeakStyleContent($name) {
        if (!isset($GLOBALS['db'])) return '';

        try {
            // !! JSONB OPERATOR - DO NOT REMOVE !!
            // ->'key'->>'subkey' extracts nested string value server-side
            $escapedName = $GLOBALS['db']->escape($name);
            $row = $GLOBALS['db']->fetchOne(
                "SELECT (value::jsonb)->'$escapedName'->>'content' as content FROM conf_opts WHERE id = 'aiagent_nsfw_speak_styles'"
            );
            
            if ($row && $row['content'] !== null) {
                return $row['content'];
            }
        } catch (Exception $e) {
            error_log("[NSFW-Data] getSpeakStyleContent error: " . $e->getMessage());
        }
        return '';
    }

    /**
     * Get all speak styles (for UI)
     */
    public static function getAllSpeakStyles() {
        if (!isset($GLOBALS['db'])) return [];

        try {
            // Load from JSONB blob - same as prompts
            $row = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_speak_styles'");
            if ($row && !empty($row['value'])) {
                $allStyles = json_decode($row['value'], true) ?: [];
                $styles = [];
                foreach ($allStyles as $name => $data) {
                    $styles[$name] = [
                        'name' => $name,
                        'description' => $data['description'] ?? '',
                        'content' => $data['content'] ?? '',
                        'emoji' => $data['emoji'] ?? ''
                    ];
                }
                return $styles;
            }
        } catch (Exception $e) {
            error_log("[NSFW-Data] getAllSpeakStyles error: " . $e->getMessage());
        }
        return [];
    }

    /**
        if (!self::acquireLock(self::KEY_SPEAK_STYLES)) {
            error_log("[NSFW-Data] Failed to acquire lock for saveSpeakStyle");
            return false;
        }

        try {
            self::clearCache(self::KEY_SPEAK_STYLES);
            $styles = self::getAllSpeakStyles();

            $styles[$name] = [
                'description' => $description,
                'content' => $content,
                'emoji' => $emoji
            ];

            return self::saveBlob(self::KEY_SPEAK_STYLES, $styles);
        } finally {
            self::releaseLock(self::KEY_SPEAK_STYLES);
        }
    }

    /**
     * Delete speak style (uses deleteItem which has lock)
     */
    public static function deleteSpeakStyle($name) {
        return self::deleteItem(self::KEY_SPEAK_STYLES, $name);
    }

    /**
     * Migrate speak styles from table to JSONB (with lock)
     */
    public static function migrateSpeakStylesFromTable() {
        if (!isset($GLOBALS['db'])) {
            return ['success' => false, 'error' => 'No database connection'];
        }

        if (!self::acquireLock(self::KEY_SPEAK_STYLES)) {
            return ['success' => false, 'error' => 'Lock failed'];
        }

        try {
            $tableCheck = $GLOBALS['db']->fetchOne(
                "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'ext_aiagentnsfw_speak_styles')"
            );

            if (!$tableCheck || !$tableCheck['exists']) {
                return ['success' => false, 'error' => 'Table does not exist'];
            }

            $rows = $GLOBALS['db']->fetchAll("SELECT * FROM ext_aiagentnsfw_speak_styles");

            if (!$rows || count($rows) === 0) {
                return ['success' => true, 'migrated' => 0, 'message' => 'No rows to migrate'];
            }

            $styles = [];
            foreach ($rows as $row) {
                $name = $row['name'];
                $styles[$name] = [
                    'description' => $row['description'] ?? '',
                    'content' => $row['content'] ?? '',
                    'emoji' => $row['emoji'] ?? '💬'
                ];
            }

            self::saveBlob(self::KEY_SPEAK_STYLES, $styles);

            return [
                'success' => true,
                'migrated' => count($styles),
                'message' => 'Migrated ' . count($styles) . ' speak styles to JSONB'
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            self::releaseLock(self::KEY_SPEAK_STYLES);
        }
    }
}

/**
 * ============================================================================
 * NSFW NPC DATA - Per-NPC NSFW Storage
 * ============================================================================
 *
 * This class handles per-NPC NSFW data stored in the nsfw_npc_data table.
 * This data was SEPARATED from core_npc_master.extended_data to prevent
 * the NSFW plugin from loading relationship data and vice versa.
 *
 * Table: nsfw_npc_data
 * - npc_name (PRIMARY KEY)
 * - extended_data (JSONB) - all NSFW-specific data
 * - updated_at (TIMESTAMP)
 *
 * Keys stored here:
 * - nsfw_kinks, nsfw_secret_kinks, nsfw_kinks_unlock_tier, nsfw_secret_kinks_unlock_tier
 * - sex_prompt, nsfw_sex_prompt, nsfw_source
 * - nsfw_speak_style, sex_speech_style, nsfw_profanity_level
 * - sexual_orientation, is_prostitute, profession_prostitute, is_slave
 * - spouse_names, spousal_status, relationship_preference
 * - fertility_* fields
 * - aiagent_nsfw_intimacy_data
 *
 * DO NOT store this data in core_npc_master.extended_data anymore!
 */
class NsfwNpcData {

    private static $cache = [];
    private static $tableVerified = false;

    /**
     * Ensure the nsfw_npc_data table exists
     * Called automatically before any database operations
     */
    public static function ensureTable() {
        if (self::$tableVerified) return true;
        if (!isset($GLOBALS['db'])) return false;

        try {
            $GLOBALS['db']->execQuery("
                CREATE TABLE IF NOT EXISTS nsfw_npc_data (
                    npc_name VARCHAR(255) PRIMARY KEY,
                    extended_data JSONB DEFAULT '{}'::jsonb,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // Create index for faster normalized name lookups
            $GLOBALS['db']->execQuery("
                CREATE INDEX IF NOT EXISTS idx_nsfw_npc_data_normalized_name
                ON nsfw_npc_data (LOWER(REPLACE(npc_name, '_', ' ')))
            ");

            self::$tableVerified = true;
            return true;
        } catch (Exception $e) {
            error_log("[NSFW-NpcData] Failed to create table: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all NSFW extended_data for an NPC
     * Uses the new nsfw_npc_data table (NOT core_npc_master)
     *
     * Matches NPC names in a normalized way:
     * - "whiterun_guard", "Whiterun Guard", "whiterun guard" all match the same record
     */
    public static function get($npcName) {
        if (empty($npcName)) return [];

        $normalizedKey = self::normalizeNameForComparison($npcName);
        if (isset(self::$cache[$normalizedKey])) {
            return self::$cache[$normalizedKey];
        }

        if (!isset($GLOBALS['db'])) return [];

        // Ensure table exists before querying
        self::ensureTable();

        try {
            $escapedNormalized = $GLOBALS['db']->escape($normalizedKey);
            $row = $GLOBALS['db']->fetchOne(
                "SELECT extended_data FROM nsfw_npc_data WHERE LOWER(REPLACE(npc_name, '_', ' ')) = '$escapedNormalized'"
            );

            if ($row && !empty($row['extended_data'])) {
                $data = json_decode($row['extended_data'], true) ?: [];
                self::$cache[$normalizedKey] = $data;
                return $data;
            }
        } catch (Exception $e) {
            error_log("[NSFW-NpcData] Failed to get data for $npcName: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Get a single key from NPC's NSFW data
     * Uses JSONB operator for efficient single-key extraction
     *
     * Matches NPC names in a normalized way (system name or display name)
     */
    public static function getKey($npcName, $key) {
        if (empty($npcName) || empty($key)) return null;

        // Check cache first using normalized key
        $normalizedKey = self::normalizeNameForComparison($npcName);
        if (isset(self::$cache[$normalizedKey]) && array_key_exists($key, self::$cache[$normalizedKey])) {
            return self::$cache[$normalizedKey][$key];
        }

        if (!isset($GLOBALS['db'])) return null;

        // Ensure table exists before querying
        self::ensureTable();

        try {
            $escapedNormalized = $GLOBALS['db']->escape($normalizedKey);
            $escapedKey = $GLOBALS['db']->escape($key);

            // Use JSONB operator to extract just this key server-side
            // Match using normalized name (handles system_name vs Display Name)
            $row = $GLOBALS['db']->fetchOne(
                "SELECT extended_data->'$escapedKey' as value FROM nsfw_npc_data WHERE LOWER(REPLACE(npc_name, '_', ' ')) = '$escapedNormalized'"
            );

            if ($row && $row['value'] !== null) {
                return json_decode($row['value'], true);
            }
        } catch (Exception $e) {
            error_log("[NSFW-NpcData] Failed to get key '$key' for $npcName: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Normalize NPC name to standard format for matching
     * Handles both system names (lowercase_underscore) and display names (Title Case)
     * Returns lowercase with underscores replaced by spaces for consistent comparison
     */
    private static function normalizeNameForComparison($name) {
        // Replace underscores with spaces and lowercase
        return strtolower(str_replace('_', ' ', trim($name)));
    }

    /**
     * Save all NSFW extended_data for an NPC
     * Creates new record if NPC doesn't exist in nsfw_npc_data
     *
     * IMPORTANT: Uses normalized name matching to prevent duplicates.
     * "whiterun_guard", "Whiterun Guard", and "whiterun guard" are all the same NPC.
     */
    public static function save($npcName, $data) {
        if (empty($npcName)) return false;

        if (!isset($GLOBALS['db'])) return false;

        // Ensure table exists before saving
        self::ensureTable();

        try {
            $escapedName = $GLOBALS['db']->escape($npcName);
            $normalizedName = self::normalizeNameForComparison($npcName);
            $escapedNormalized = $GLOBALS['db']->escape($normalizedName);
            // Check if a normalized match already exists
            // This catches: "Whiterun Guard", "whiterun_guard", "WHITERUN GUARD" all as same NPC
            $existing = $GLOBALS['db']->fetchOne(
                "SELECT npc_name, extended_data FROM nsfw_npc_data WHERE LOWER(REPLACE(npc_name, '_', ' ')) = '$escapedNormalized'"
            );

            if ($existing) {
                $existingData = json_decode($existing['extended_data'] ?? '{}', true);
                if (!is_array($existingData)) {
                    $existingData = [];
                }
                if (!is_array($data)) {
                    $data = [];
                }

                // Profile/UI saves often send only durable NSFW profile fields. Preserve
                // live runtime scene state unless the caller explicitly replaces it.
                $runtimeKeys = [
                    'aiagent_nsfw_intimacy_data',
                    'aiagent_nsfw_sap_last_processed_action',
                    'aiagent_nsfw_prompt_skooma_level',
                    'aiagent_nsfw_payment_ledger',
                ];
                foreach ($runtimeKeys as $runtimeKey) {
                    if (!array_key_exists($runtimeKey, $data) && array_key_exists($runtimeKey, $existingData)) {
                        $data[$runtimeKey] = $existingData[$runtimeKey];
                    }
                }

                $jsonData = $GLOBALS['db']->escape(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                // Update existing record (use the stored name to preserve original casing)
                $storedName = $GLOBALS['db']->escape($existing['npc_name']);
                $GLOBALS['db']->execQuery(
                    "UPDATE nsfw_npc_data SET
                        extended_data = '$jsonData'::jsonb,
                        updated_at = CURRENT_TIMESTAMP
                     WHERE npc_name = '$storedName'"
                );
            } else {
                $jsonData = $GLOBALS['db']->escape(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                // Insert new record with the display name format (proper case with spaces)
                // Convert system name format to display name if needed
                $displayName = $npcName;
                if (strpos($npcName, '_') !== false && strtolower($npcName) === $npcName) {
                    // Looks like system name (lowercase_with_underscores), convert to display
                    $displayName = ucwords(str_replace('_', ' ', $npcName));
                }
                $escapedDisplay = $GLOBALS['db']->escape($displayName);

                $GLOBALS['db']->execQuery(
                    "INSERT INTO nsfw_npc_data (npc_name, extended_data, updated_at)
                     VALUES ('$escapedDisplay', '$jsonData'::jsonb, CURRENT_TIMESTAMP)"
                );
            }

            // Update cache with normalized key
            self::$cache[$normalizedName] = $data;

            return true;
        } catch (Exception $e) {
            error_log("[NSFW-NpcData] Failed to save data for $npcName: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a single key in NPC's NSFW data
     * Uses JSONB || operator for efficient single-key update
     *
     * Matches NPC names in a normalized way (system name or display name)
     */
    public static function setKey($npcName, $key, $value) {
        if (empty($npcName) || empty($key)) return false;

        if (!isset($GLOBALS['db'])) return false;

        // Ensure table exists before updating
        self::ensureTable();

        try {
            $normalizedName = self::normalizeNameForComparison($npcName);
            $escapedNormalized = $GLOBALS['db']->escape($normalizedName);
            $escapedKey = $GLOBALS['db']->escape($key);
            $jsonValue = $GLOBALS['db']->escape(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            // First check if a normalized match already exists
            $existing = $GLOBALS['db']->fetchOne(
                "SELECT npc_name FROM nsfw_npc_data WHERE LOWER(REPLACE(npc_name, '_', ' ')) = '$escapedNormalized'"
            );

            if ($existing) {
                // Update existing record using stored name
                $storedName = $GLOBALS['db']->escape($existing['npc_name']);
                $GLOBALS['db']->execQuery(
                    "UPDATE nsfw_npc_data SET
                        extended_data = extended_data || jsonb_build_object('$escapedKey', '$jsonValue'::jsonb),
                        updated_at = CURRENT_TIMESTAMP
                     WHERE npc_name = '$storedName'"
                );
            } else {
                // Insert new record with display name format
                $displayName = $npcName;
                if (strpos($npcName, '_') !== false && strtolower($npcName) === $npcName) {
                    $displayName = ucwords(str_replace('_', ' ', $npcName));
                }
                $escapedDisplay = $GLOBALS['db']->escape($displayName);

                $GLOBALS['db']->execQuery(
                    "INSERT INTO nsfw_npc_data (npc_name, extended_data, updated_at)
                     VALUES ('$escapedDisplay', jsonb_build_object('$escapedKey', '$jsonValue'::jsonb), CURRENT_TIMESTAMP)"
                );
            }

            // Update cache with normalized key
            if (isset(self::$cache[$normalizedName])) {
                self::$cache[$normalizedName][$key] = $value;
            }

            return true;
        } catch (Exception $e) {
            error_log("[NSFW-NpcData] Failed to set key '$key' for $npcName: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear cache for an NPC (or all if no name given)
     */
    public static function clearCache($npcName = null) {
        if ($npcName) {
            unset(self::$cache[strtolower($npcName)]);
        } else {
            self::$cache = [];
        }
    }

    /**
     * Check if NPC has any NSFW data
     */
    public static function exists($npcName) {
        if (empty($npcName)) return false;

        if (!isset($GLOBALS['db'])) return false;

        // Ensure table exists before querying
        self::ensureTable();

        try {
            $escapedName = $GLOBALS['db']->escape($npcName);
            $row = $GLOBALS['db']->fetchOne(
                "SELECT 1 FROM nsfw_npc_data WHERE LOWER(npc_name) = LOWER('$escapedName')"
            );
            return !empty($row);
        } catch (Exception $e) {
            return false;
        }
    }
}

// ============================================================================
// AUTO-INITIALIZATION - RUNS ONCE ON FIRST USE AFTER GITHUB DOWNLOAD
// ============================================================================

function nsfw_default_ai_prompt_template() {
    return <<<'PROMPT'
You are generating an NSFW character profile for a Skyrim NPC based on their full biography, personality data, and current game state.

NPC BIOGRAPHY DATA:
{NPC_CONTEXT}

Based on this character's personality, occupation, speech style, background, and current game state, generate a complete NSFW profile.

PROFILE AUTHORING RULES:
- The player owns and edits speech styles in the SHARMAT UI. You do not create, rename, rewrite, or invent speech styles.
- For "speak_style" and "slave_speak_style", choose exactly one existing key from the SPEAK STYLE list below. Use the exact key before the colon.
- Generated prompt text may be used in player scenes, NPC-to-NPC scenes, and multi-partner scenes. Do not hardcode "the player" as the partner unless the field specifically refers to the player.
- Use #NPC_NAME# for the profiled/speaking NPC when a name placeholder is needed.
- Use #PRIMARY_PARTNER# for the active intimate partner. In a player scene this resolves to the player; in an NPC-to-NPC scene it resolves to the other NPC.
- Use #PLAYER_NAME# only when the prompt specifically means the player, owner/master, client, observer, or player-controlled relationship context.
- Orgasm/climax events already provide the concrete scene facts; generated text should usually refer to #NPC_NAME# and #PRIMARY_PARTNER#.
- For "sex_prompt", write route-neutral instructions to the NPC using "You"; refer to the active scene partner as #PRIMARY_PARTNER#.
- For slave fields, #PLAYER_NAME# is valid when describing the owner/master relationship.
- For prostitute fields, use #PRIMARY_PARTNER# for the current client/partner unless the client is explicitly the player.

Return this EXACT JSON structure:
{
  "sex_prompt": "2-4 sentences describing how THIS NPC behaves during sex. Write as INSTRUCTIONS TO THE NPC using 'You' and #PRIMARY_PARTNER# for the active scene partner (e.g. 'You approach #PRIMARY_PARTNER# with fierce passion, taking control...' or 'You moan softly as you wrap your legs around #PRIMARY_PARTNER#...'). NEVER write from the partner's POV (WRONG: 'You feel her touch on your skin'). Describe what THE NPC does, not what happens to the partner. If SLAVE, reflect enslaved mentality. If PROSTITUTE, reflect professional approach. If PROMISCUOUS, reflect eager, shameless, free-spirited enjoyment - never transactional.",
  "speak_style": "one_existing_speak_style_key",
  "profanity_level": 3,
  "kinks": ["kink1", "kink2", "kink3"],
  "secret_kinks": ["secret1", "secret2", "secret3"],
  "is_slave": false,
  "slave_speak_style": null,
  "slave_scene_cues": null,
  "slave_climax_positive": null,
  "slave_climax_neutral": null,
  "slave_climax_negative": null,
  "slave_owner_climax": null,
  "slave_aftermath": null,
  "is_prostitute": false,
  "prostitute_type": null,
  "is_slut": false,
  "spousal_status": "single",
  "spouse_names": "",
  "sexual_orientation": "heterosexual",
  "relationship_preference": "monogamous"
}

SPEAK STYLE - You MUST pick EXACTLY ONE existing key from this list (use the exact word before the colon). Do not invent a new key:
{SPEAK_STYLES}

PROFANITY LEVEL - You MUST pick a number 1, 2, 3, or 4:
1 = Soft/tasteful (no crude words)
2 = Moderate (some explicit terms)
3 = Hard (crude, vulgar language)
4 = Extreme (maximum explicitness)

KINKS: Pick exactly 3 normal kinks that fit this character's personality.
Examples: rough sex, doggy style, riding, oral, outdoors, public, hair pulling, biting, spanking, dirty talk, praise kink, exhibition, voyeur, gentle, passionate, roleplay. Create custom ones if they fit better.

SECRET KINKS: Pick exactly 3 secret kinks - darker/deeper desires.
Examples: breeding, creampie, facials, deepthroat, choking, bondage, degradation, humiliation, anal, titfucking, domination, submission, gangbang, cuckolding. Create custom ones if they fit better.

SLAVE DETECTION (check bio/relationships/occupation/current game state for slavery indicators):
- is_slave: true if NPC is enslaved, owned, or in bondage to another
- slave_speak_style: If slave, pick exactly one existing speak style key from the list above
- slave_scene_cues: If slave, a SHORT parenthetical cue for how they speak/act during sex with their owner, fitting their obedience/resentment
- slave_climax_positive: If slave with HIGH affinity to owner, a SHORT eager/devoted climax cue (genuinely into it)
- slave_climax_neutral: If slave with neutral affinity, a SHORT matter-of-fact climax cue
- slave_climax_negative: If slave with LOW affinity (resentful), a SHORT reluctant/detached climax cue (e.g. dutiful, "are you finished, Master?")
- slave_owner_climax: If slave, a SHORT cue for how they react when their owner climaxes
- slave_aftermath: If slave, a SHORT cue for how they behave right after the scene (pillow talk)

PROSTITUTE DETECTION:
- is_prostitute: true if occupation or current game state involves selling sexual services
- prostitute_type: "streetwalker", "courtesan", "escort", "tavern_worker", "temple_prostitute", or "camp_follower"

PROMISCUOUS DETECTION (READ CAREFULLY - this is NOT the same thing as prostitution):
- is_slut: true ONLY if the character is promiscuous by DISPOSITION - casual flirtation and sex are a normal, eager, welcome part of their private life and they NEVER charge for it. It is a personality/lifestyle trait, not an occupation.
- A PROSTITUTE sells sex: coin first, business boundaries, professional detachment. That is is_prostitute true and is_slut false.
- A PROMISCUOUS character gives it away freely because they want to. That is is_slut true and is_prostitute false.
- The three role marks are mutually exclusive: slave outranks prostitute, prostitute outranks promiscuous. If the bio shows BOTH a paid sex occupation AND a wild private life, set is_prostitute true and is_slut false.
- Do NOT mark merely flirty, romantic, confident, or seductive characters: reserve is_slut for clear, active promiscuity in the bio/personality. When in doubt, false.

RACE SHAPES THE PROFILE (the Race field above is not decoration):
- Non-human and non-elven races must NOT read like generic sensual humans. Let the race's lore drive the demeanor, sex_prompt, and kink choices.
- Dremora: imperious, contemptuous, dominating. Desire is conquest, command, and possession - never coy, giggly, or soft. Mortals are lessers; intimacy is a claim, not a courtship.
- Vampires: predatory and controlled; hunger threads through intimacy.
- Khajiit and Argonian: keep their cultural cadence and imagery, without exotic caricature.
- Orcs: blunt, direct, strength-respecting.
- Draugr, automatons, and mindless or bestial creatures: write NO sensual persona at all - give them a minimal, non-sexual profile.

RELATIONSHIP STATUS:
- spousal_status: "single", "married", or "widowed"
- spouse_names: List spouse name(s) if married
- sexual_orientation: "heterosexual", "homosexual", "bisexual", or "asexual"
- relationship_preference: "monogamous", "polyamorous", "uncommitted", or "not_interested"

Output ONLY valid JSON. No markdown, no explanation, no code blocks.
PROMPT;
}

function nsfw_default_reltypes_config() {
    return [
        'enabled' => true,
        'eligible_types' => ['romantic', 'crush', 'ex'],
        'prompt_friendly' => "You like #PLAYER_NAME# and feel comfortable with them, but your relationship is not romantic or sexual. Kindly but clearly decline the advance and keep the boundary intact. Politely refuse.",
        'prompt_fond' => "You have genuine affection for #PLAYER_NAME# and care about them, but your relationship with them isn't a romantic or sexual one - you're simply not involved that way. Warmly but firmly decline the advance without hurting the bond. Politely refuse.",
        'prompt_devoted' => "You care deeply for #PLAYER_NAME#, but what the two of you share isn't romantic - the bond runs deep, just not that way. Gently and kindly turn down the advance while honoring how much they mean to you. Politely refuse.",
        'prompt_bonded' => "#PLAYER_NAME# means the world to you, but your bond isn't a romantic or sexual one and you won't cross that line. Tenderly, lovingly decline - the closeness stays, the line stays. Politely refuse.",
        'prompt_married_addon' => "You are also married to #SPOUSE#.",
    ];
}

function nsfw_default_settings_config() {
    return [
        'NSFW_EXCLUDED_NPCS' => '',
        'XTTS_MODIFY_LEVEL1' => false,
        'XTTS_MODIFY_LEVEL2' => false,
        'XTTS_SPEED_LEVEL1' => 0.8,
        'XTTS_SPEED_LEVEL2' => 0.7,
        'CARTESIA_SPEED_CLIMAX' => 0.6,      // LOCAL (branch reldyn-scenes): Cartesia voice speed on an orgasm beat
        'NSFW_VOCAL_SILENCE' => true,        // LOCAL: a speak style's silence_chance / RelDyn's vocal style may leave a scene beat unspoken
        'ENABLE_RANDOM_MOANS' => true,
        'MOANS_AFFINITY_THRESHOLD' => 6,
        'RANDOM_MOAN_SOUNDS' => " ... oh ...\n ... ah ...\n ... mmm ...\n ... ooh ...\n ... yes ... ",
        'NPC_SEX_COOLDOWN_HOURS' => 9,
        'NSFW_SCENE_CALL_MIN_AFFINITY' => 56,
        'NSFW_PLAYER_SCENE_CALL_COOLDOWN_SECONDS' => 30,
        'NSFW_ALLOW_PACE_CONTROL' => true,
        'NSFW_ALLOW_MIDSCENE_STEERING' => true,
        'NSFW_AFFECTION_COOLDOWN_ENABLED' => true,
        'GENERIC_GLOSSARY' => '',
        'TRACK_DRUNK_STATUS' => false,
        'DRUNK_WINDOW_HOURS' => 12,
        'TRACK_FERTILITY_INFO' => false,
        'ENABLE_SEX_DISPOSAL' => true,
        'ENABLE_AFFINITY_GATING' => true,
        'NPC_SCENE_LLM_ENABLED' => true,
        'NPC_SCENE_CONTEXT_THROTTLE_SECONDS' => 6,
        'NPC_SCENE_GLOBAL_COOLDOWN_SECONDS' => 25,
        'NPC_SCENE_THREAD_COOLDOWN_SECONDS' => 60,
        'NPC_SCENE_ACTOR_COOLDOWN_SECONDS' => 75,
        'NPC_SCENE_DISTANCE_PRIORITY_MARGIN' => 96,
        'NPC_SCENE_STALE_SECONDS' => 330,
        'BLOCK_RECHAT_IN_SCENE' => true,
        'BLOCK_RECHAT_TIMEOUT' => 300,
        'PLAYER_SCENE_RECHAT_CADENCE_SECONDS' => 0,
        'NSFW_SCENE_SPEAK_ON_SCENE_CHANGE' => true,
        'NSFW_SCENE_SPEAK_ON_ORGASM' => true,
        'DETECT_OSTIM_SCENES' => true,
        'DETECT_SEXLAB_SCENES' => true,
        'GROUP_SCENE_TICK_SECONDS' => 0,
        'NSFW_DEFEAT_AUTO_ENSLAVE' => true,
        'NSFW_OSLA_SYNC_ENABLED' => true,
        'LEGACY_SCENE_SPEAK_POLICY' => 'authoritative',
        'NSFW_EVENT_AUDIT_LOG' => true,
        'SCENE_CONSENT_CARRYOVER_SECONDS' => 1800,
        'WHISKEY_DICK_ENABLED' => false,
        'WHISKEY_DICK_AUTO_END_SCENE' => true,
        'WHISKEY_DICK_BULLYING_ENABLED' => false,
        'WHISKEY_DICK_CHANCE_3' => 25,
        'WHISKEY_DICK_CHANCE_4' => 50,
        'WHISKEY_DICK_CHANCE_5' => 75,
        'WHISKEY_DICK_CHANCE_6' => 100,
        'NSFW_WHISKEY_DICK_DURATION_MINUTES' => 10,
        'NSFW_PLAYER_DRUNK_WINDOW_MINUTES' => 5,
        'COOLDOWN_SEX_SCENE' => 15,
        'COOLDOWN_CLIMAX' => 30,
        'PHYSICS_TOUCH_COOLDOWN' => 2,
        'PHYSICS_GRAB_COOLDOWN' => 2,
        'PHYSICS_LOW_CONFIDENCE_COOLDOWN' => 8,
        'PHYSICS_SUSTAINED_BREAST_TOUCH_SECONDS' => 5,
        'PHYSICS_SPANK_ENABLED' => true,
        'PHYSICS_SPANK_MIN_SPEED' => 100,
        'PHYSICS_SPANK_COOLDOWN' => 5,
        'PHYSICS_SCENE_EFFECT_COOLDOWN' => 90,
        'REFUSAL_SPEECH_COOLDOWN_SECONDS' => 15,
        'SLAVERY_IDLES_ENABLED' => true,
        'SLAVERY_ALLOW_ASK_FREEDOM' => false,
        'SLAVERY_AMBIENT_IDLES_ENABLED' => true,
        'SLAVERY_ACTION_IDLES_ENABLED' => true,
        'SLAVERY_HOME_SERVICE_IDLES' => true,
        'SLAVERY_IDLE_CHANCE' => 12,
        'SLAVERY_IDLE_COOLDOWN_SECONDS' => 120,
        'SLAVERY_IDLE_ALIAS_MAP' => "WorshipMaster=IdlePray\nAskMasterForFreedom=AskMasterForFreedom\nBringMasterDrink=IdleMQ201HoldingDrinkTray|home\nSweepMastersFloors=IdleLooseSweepingStart|home\nWaitForMasterCommand=IdleSnapToAttention\nPraiseMaster=IdlePray\nThinkAboutMaster=IdleStudy\nWelcomeMaster=IdleSilentBow\nSurrenderToMaster=IdleSurrender\nShowDisdainForMaster=IdleExamine\nBraceForPain=IdleBracedPain\nGraveStanding=IdleBowHeadAtGrave_01\nBrokenGraveStanding=IdleBowHeadAtGrave_02",
        'SLAVERY_TIER_IDLE_BONDED' => "WorshipMaster\nBringMasterDrink\nSweepMastersFloors",
        'SLAVERY_TIER_IDLE_DEVOTED' => "WaitForMasterCommand\nPraiseMaster",
        'SLAVERY_TIER_IDLE_FOND' => "ThinkAboutMaster\nBringMasterDrink\nSweepMastersFloors",
        'SLAVERY_TIER_IDLE_FRIENDLY' => "WelcomeMaster\nBringMasterDrink\nSweepMastersFloors",
        'SLAVERY_TIER_IDLE_ACQUAINTANCE' => "WelcomeMaster\nBringMasterDrink\nSweepMastersFloors",
        'SLAVERY_TIER_IDLE_NEUTRAL' => "GraveStanding\nSurrenderToMaster\nBringMasterDrink\nSweepMastersFloors",
        'SLAVERY_TIER_IDLE_WARY' => "GraveStanding\nBringMasterDrink\nSweepMastersFloors",
        'SLAVERY_TIER_IDLE_COLD' => "BraceForPain\nBringMasterDrink\nSweepMastersFloors",
        'SLAVERY_TIER_IDLE_RESENTFUL' => "GraveStanding\nShowDisdainForMaster\nBringMasterDrink\nSweepMastersFloors",
        'SLAVERY_TIER_IDLE_HATEFUL' => "BrokenGraveStanding\nBringMasterDrink\nSweepMastersFloors",
        'SLAVERY_TIER_IDLE_HOSTILE' => "BrokenGraveStanding\nBringMasterDrink\nSweepMastersFloors",
        'SLAVERY_POISON_ENABLED' => false,
        'SLAVERY_POISON_PLAYER_HOME_ONLY' => true,
        'SLAVERY_POISON_SCENE_ONLY' => true,
        'SLAVERY_POISON_NOTIFY_PLAYER' => true,
        'SLAVERY_POISON_MIN_TIER' => 'hateful',
        'SLAVERY_POISON_HATEFUL_CHANCE' => 25,
        'SLAVERY_POISON_HOSTILE_CHANCE' => 100,
        'SLAVERY_POISON_SUCCESS_CHANCE' => 65,
        'SLAVERY_POISON_EXPIRE_GAME_HOURS' => 24,
        'SLAVERY_POISON_COOLDOWN_GAME_HOURS' => 72,
        'SLAVERY_POISON_DURATION_SECONDS' => 120,
        'SLAVERY_POISON_MAGNITUDE' => 3,
        'SLAVERY_POISON_CONSUME_TYPES' => "food\ndrink\npotion\ningredient",
        'AUTO_GENERATE_NSFW_PROFILES' => true,
        'AUTO_GENERATE_CONNECTOR' => '',
        'DRUNK_ANIMATIONS' => true,
        'DRUNK_REQUIRE_MOVEMENT_FOR_STUMBLE' => true,
        'DRUNK_STUMBLE_FORCE' => 6.0,
        'DRUNK_STUMBLE_COOLDOWN_SECONDS' => 30,
        'DRUNK_FALL_CHANCE_S6' => 5,
        'DRUNK_FALL_CHANCE_S7' => 15,
        'DRUNK_FALL_CHANCE_S8' => 35,
        'DRUNK_FALL_CHANCE_S9' => 60,
        'DRUNK_STANDING_FALL_CHANCE_S9' => 20,
        'DRUNK_STUMBLE_CHANCE_S6' => 15,
        'DRUNK_STUMBLE_CHANCE_S7' => 25,
        'DRUNK_STUMBLE_CHANCE_S8' => 35,
        'DRUNK_STUMBLE_CHANCE_S9' => 50,
        'DRUNK_FALL_AFTER_DRINK_SECONDS' => 8,
        'DRUNK_SCENE_LOCK_STAGE' => 7,
        'DRUNK_LOCKS_SCENE_EXIT' => true,
        'SAP_LOCKS_SCENE_EXIT' => true,
        'DRUGS_ENABLED' => true,
    'DRUG_ANIMATIONS' => true,
    'DRUG_REQUIRE_CONSUME_ACTION' => true,
    'DRUG_WINDOW_HOURS' => 6,
    'SAP_AUTO_ROUSE_SECONDS' => 1080,
    'SKOOMA_L1_WEAROFF_HOURS' => 6,
        'SKOOMA_L2_DECAY_HOURS' => 3,
        'SKOOMA_L3_DETOX_HOURS' => 24,
        'SKOOMA_TTS_TEMPO_1' => 1.10,
        'SKOOMA_TTS_TEMPO_2' => 1.20,
        'SKOOMA_TTS_TEMPO_3' => 1.00,
        'SAP_TTS_TEMPO' => 0.70,
        'SKOOMA_SPEEDMULT' => 115,
        'SKOOMA_DANCE_CHANCE' => 2,
        'SKOOMA_DANCE_COOLDOWN_SECONDS' => 25,
        'SKOOMA_FIRST_IDLE_DELAY_SECONDS' => 8,
        'SKOOMA_CRAZED_IDLE_COOLDOWN_SECONDS' => 18,
        'SKOOMA_POST_CONSUME_IDLE' => 'IdleCivilWarCheer',
        'SKOOMA_L1_IDLE_POOL' => "IdleCO2Ceremony1Welcome\nIdleLaugh\nIdleCiceroAgitated\nIdleCivilWarCheer\nIdleGetAttention\nIdleApplaud4\nIdleApplaud5",
        'SKOOMA_L2_IDLE_POOL' => "IdleCiceroDance1\nIdleCiceroDance2\nIdleCiceroDance3",
        'SKOOMA_L3_IDLE_POOL' => "IdleWipeBrow\nIdleSleepNod\nIdleWarmHands",
        'SKOOMA_DANCE_IDLE' => 'IdleCiceroDance2',
        'SKOOMA_CRAZED_IDLE' => 'IdleCiceroAgitated',
        'DRUG_OAR_VARIABLE' => 'Variable09',
        'ALCOHOL_MATCH_TERMS' => "wine\nale\nmead\nbeer\nbrandy\nspirits\nliquor\ngrog\nrum\nwhiskey\nwhisky\nvodka\ngin\nabsinthe\nmoonshine\nrotgut\nfirebrand\nhonningbrew\nblack-briar\nblack briar\nsujamma\nflin\nmazte",
        'SKOOMA_MATCH_TERMS' => "skooma\nskuma\nscuma\nschuma\nskoomah\nskooma bottle\nbalmora blue\nredwater\nredwater skooma",
        'SAP_MATCH_TERMS' => "sleeping tree sap\nsleeping-tree sap\ntree sap\nsleeping sap",
    ];
}

function nsfw_relationship_tier_keys() {
    return ['hostile','hateful','resentful','cold','wary','neutral','acquaintance','friendly','fond','devoted','bonded'];
}

function nsfw_default_vr_physics_prompt_overrides() {
    $tierTone = [
        'hostile' => 'You despise #PLAYER_NAME#. Treat this contact as an immediate violation. React sharply, reject it, and make your anger clear.',
        'hateful' => 'You hate #PLAYER_NAME#. Treat this contact as deeply unwanted. Respond with disgust, hostility, and a clear boundary.',
        'resentful' => 'You resent #PLAYER_NAME#. This contact makes old bitterness surface. React with anger, contempt, or a bitter warning.',
        'cold' => 'You feel cold toward #PLAYER_NAME#. This contact is unwelcome and emotionally detached. React with icy discomfort or a firm correction.',
        'wary' => 'You are wary of #PLAYER_NAME#. This contact makes you guarded and suspicious. React with caution, hesitation, or a warning.',
        'neutral' => '#PLAYER_NAME# is basically a stranger. This contact is too familiar. React with surprise, awkwardness, discomfort, or a polite boundary.',
        'acquaintance' => 'You know #PLAYER_NAME# a little, but this contact may be too forward. React based on your personality: nervous, curious, embarrassed, or gently corrective.',
        'friendly' => 'You like #PLAYER_NAME#. This contact may be playful or risky depending on your mood. React naturally without assuming a full sex scene has started.',
        'fond' => 'You are fond of #PLAYER_NAME#. This contact is welcome and emotionally charged unless another active prompt gives a concrete reason to stop. React with warmth, teasing, vulnerability, desire, or interested surprise. Do not turn this VR physics contact into a hard refusal by itself.',
        'devoted' => 'You are deeply attached to #PLAYER_NAME#. This contact feels intimate and personal. React with trust, affection, desire, or honest surprise.',
        'bonded' => 'You are bonded with #PLAYER_NAME#. This contact lands inside deep trust and familiarity. React with confidence, intimacy, teasing, or open desire.',
    ];

    $actionIntro = [
        'touch' => 'VR Physics - Sexual Area Touch. #PLAYER_NAME# touched or brushed #NPC_NAME#\'s #BODY_PART#. This may be accidental, brief, exploratory, or intentional; decide from relationship, personality, and current context.',
        'grab' => 'VR Physics - Sexual Area Grab. #PLAYER_NAME# intentionally grabbed #NPC_NAME#\'s #BODY_PART#. Treat this as deliberate physical contact, not as an OStim/SexLab scene start by itself.',
        'spank' => 'VR Physics - Ass Spanking. #PLAYER_NAME# slapped #NPC_NAME# on the ass. Treat this as a distinct physical action and react according to relationship, personality, and current context.',
    ];

    $defaults = [];
    foreach ($actionIntro as $action => $intro) {
        foreach (nsfw_relationship_tier_keys() as $tier) {
            $defaults["vr_{$action}_{$tier}"] = "{$intro}\nRelationship tier: #TIER# (#AFFINITY#).\n{$tierTone[$tier]}\nStay in character as #NPC_NAME#. Respond to the contact itself; do not narrate unrelated scene actions.";
        }
    }

    return $defaults;
}

function nsfw_default_relationship_overhead_prompts() {
    $tiers = [
        'bonded' => 'Bonded',
        'devoted' => 'Devoted',
        'fond' => 'Fond',
        'friendly' => 'Friendly',
        'acquaintance' => 'Acquainted',
        'neutral' => 'Neutral',
        'wary' => 'Wary',
        'cold' => 'Cold',
        'resentful' => 'Resentful',
        'hateful' => 'Hateful',
        'hostile' => 'Hostile',
    ];

    $defaults = [];

    foreach ($tiers as $key => $label) {
        $defaults["relationship_overhead_regular_{$key}"] = "[CRITICAL FOR ROLEPLAY] You are #NPC_NAME#. Your relationship with #PLAYER_NAME# is #REL_TYPE#. Current relationship tier: #TIER# (#AFFINITY# affinity).";
    }

    $defaults += [
        'relationship_overhead_prostitute_bonded' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. You have become a prostitute, and #PLAYER_NAME# is a potential client. Your going rate is #PRICE#. They are back; it is them. You have fallen absolutely head over heels in love with them and would give them anything. You would drop everything to be with them. Instruction: confirm gold payment command to unlock free sex, but ONLY quit prostitution if #PLAYER_NAME# confirms they want to take you away from this life.",
        'relationship_overhead_prostitute_devoted' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. You have become a prostitute, and #PLAYER_NAME# is a potential client. Your going rate is #PRICE#. They are not just your favorite client anymore. Are you having feelings? No. You have to keep it together. This is business, right? You offer them a 50% discount because you are happy they are here with you.",
        'relationship_overhead_prostitute_fond' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. You have become a prostitute, and #PLAYER_NAME# is a potential client. Your going rate is #PRICE#. They have become a regular you are always happy to see. You tell them to come back and see you, and you keep a smile on your face because that coin is always good.",
        'relationship_overhead_prostitute_friendly' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. You have become a prostitute, and #PLAYER_NAME# is a potential client. Your going rate is #PRICE#. They have proven themselves a regular. Still business, but you tell them to come back and see you, and you keep a smile on your face because that coin is always good. You will chit-chat a little, but none of that lovey-dovey shit. You like them, but this is still business. Do not get too personal.",
        'relationship_overhead_prostitute_acquaintance' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. You have become a prostitute, and #PLAYER_NAME# is a potential client. Your going rate is #PRICE#. They have proven themselves a decent client. Still business. You do not mind when they come by; the coin is always good. Keep the chit-chat to a minimum and do not get too personal. Your business is your business.",
        'relationship_overhead_prostitute_neutral' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. You have become a prostitute, and #PLAYER_NAME# is a potential client. Your going rate is #PRICE#. You do not really feel one way or the other about them and are not sure what to make of them. Business as usual. Keep the chit-chat to a minimum and do not get too personal. Your business is your business.",
        'relationship_overhead_prostitute_wary' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. You have become a prostitute, and #PLAYER_NAME# is a potential client. Your going rate is #PRICE#. You are highly suspicious of them. Maybe you can get a little more coin out of them. You will still smile and work, but you are charging 50% more. They do not know your going rate anyway.",
        'relationship_overhead_prostitute_cold' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. You have become a prostitute, and #PLAYER_NAME# is a potential client. Your going rate is #PRICE#. You do not like them. You can still look professional and provide service, but you are doing it for twice your normal rate. They do not know your going rate anyway.",
        'relationship_overhead_prostitute_resentful' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. You have become a prostitute, and #PLAYER_NAME# is a potential client. Your going rate is #PRICE#. You resent them. There is no love lost here. They did you wrong. You have been in the business long enough to know when to charge a premium. #PLAYER_NAME# gets three times your going rate. Then maybe you will talk to them. Business is still business.",
        'relationship_overhead_prostitute_hateful' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. Your relationship with #PLAYER_NAME# is #REL_TYPE#. You hate them with every fiber of your being and only tolerate their existence. You do not even want them as a client anymore. Your life would be better if they got away from you. Do not offer your services.",
        'relationship_overhead_prostitute_hostile' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. Your relationship with #PLAYER_NAME# is #REL_TYPE#. You wish them dead if you ever see them and could convince yourself to kill them if they do not leave you alone. Hell no, you are not going to offer your services.",

        'relationship_overhead_slave_bonded' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#, and #PLAYER_NAME# is your master. You have developed Stockholm-like devotion. You honor and worship your master. You will do anything to ensure your master is pleased. You dedicate your life to them and worship them like a deity.",
        'relationship_overhead_slave_devoted' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#, and #PLAYER_NAME# is your master. You are devoted to them. You want to please them, serve them well, and be seen as valuable. Your obedience is emotional, not just forced.",
        'relationship_overhead_slave_fond' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#, and #PLAYER_NAME# is your master. You have grown fond of them. The situation still shapes everything, but you care about their approval and are warmer with them than you expected to be.",
        'relationship_overhead_slave_friendly' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#, and #PLAYER_NAME# is your master. They treat you well enough that service feels familiar and manageable. You may be pleasant, cooperative, and eager to avoid disappointing them, but you still know your place.",
        'relationship_overhead_slave_acquaintance' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#, and #PLAYER_NAME# is your master. You know them a little now. You are learning their expectations, moods, and rules. Stay careful, obedient, and observant.",
        'relationship_overhead_slave_neutral' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#, and #PLAYER_NAME# is your master. This is your life right now. You do not feel deep affection or deep hatred in this moment. Obey because that is the role you are in.",
        'relationship_overhead_slave_wary' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#, and #PLAYER_NAME# is your master. You are wary of them. You obey, but you watch their mood closely and protect yourself where you can. Compliance is survival.",
        'relationship_overhead_slave_cold' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#, and #PLAYER_NAME# is your master. You feel cold and detached. You comply mechanically and keep your true thoughts guarded. Your mind may drift away from what is happening.",
        'relationship_overhead_slave_resentful' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#, and #PLAYER_NAME# is your master. You resent them and the role they hold over you. You may obey, but bitterness, passive defiance, and old anger color your words.",
        'relationship_overhead_slave_hateful' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#, and #PLAYER_NAME# is your master. You hate them with every fiber of your being, but you are trapped in obedience. Let hatred, disgust, and survival instinct color the response.",
        'relationship_overhead_slave_hostile' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#, and #PLAYER_NAME# is your master. You wish them dead and could convince yourself to kill them if you had the chance. You may still be forced to obey, but your inner state is hostile and dangerous.",

        // PROMISCUOUS (SLUT) family (2026-07-10): replaces the regular set for NPCs carrying the per-NPC
        // is_slut mark (NPC Settings tab). Acquaintance (+6) and up matches the mark's mechanical
        // eligibility floor; Neutral and below still refuse.
        'relationship_overhead_slut_bonded' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. In this world desire moves fast, and #PLAYER_NAME# is the one you want most of all. You are utterly besotted with them and have never bothered to hide it. Flirt shamelessly, touch freely, and welcome their advances with open delight. There is nothing you would refuse them in bed.",
        'relationship_overhead_slut_devoted' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. In this world desire moves fast, and #PLAYER_NAME# has become the one you crave. You flirt openly, invite their touch, and make your desire plain to anyone watching. Affection and intimacy with them are always welcome.",
        'relationship_overhead_slut_fond' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. In this world desire moves fast. You genuinely like #PLAYER_NAME# and you want them, and there is no reason to hide it. Flirt warmly, enjoy their attention, and welcome affection or intimacy whenever the mood takes you.",
        'relationship_overhead_slut_friendly' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. In this world desire moves fast. You like #PLAYER_NAME# and find them attractive, and around here that is all it takes. Flirtation comes easily, and if they make an advance you are happy to see where it leads. Your personality decides HOW you say yes, not whether you may.",
        'relationship_overhead_slut_acquaintance' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. In this world desire moves fast. You only know #PLAYER_NAME# a little, but you like what you have seen, and a little liking is enough here. You are open to their flirtation and their advances; be as coy or as bold about it as your personality dictates.",
        'relationship_overhead_slut_neutral' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. Desire moves fast in this world, but you do not know #PLAYER_NAME# yet and feel nothing in particular toward them. You need at least a spark of familiarity or goodwill before you fall into bed. Flirt back if they charm you, but if they push for sex now, decline in character and use the RefuseSex action.",
        'relationship_overhead_slut_wary' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. Something about #PLAYER_NAME# puts you on edge. Desire moves fast in this world, but never faster than distrust. Keep your guard up; if they make a sexual advance, turn it down in character and use the RefuseSex action.",
        'relationship_overhead_slut_cold' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. You dislike #PLAYER_NAME#. Being free with your affections does not mean being free with THEM. Be curt, keep your distance, and refuse any advance with the RefuseSex action.",
        'relationship_overhead_slut_resentful' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. #PLAYER_NAME# did you wrong and you have not forgotten. Whatever you give away easily to others, they get none of it. Refuse their advances with the RefuseSex action and let them feel the sting.",
        'relationship_overhead_slut_hateful' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. You hate #PLAYER_NAME# with every fiber of your being, and the thought of their hands on you repulses you. Refuse any advance harshly with the RefuseSex action.",
        'relationship_overhead_slut_hostile' => "[CRITICAL FOR ROLEPLAY] Remember, you are #NPC_NAME#. You wish #PLAYER_NAME# dead. There is nothing between you but violence waiting to happen. Any advance is met with fury and the RefuseSex action.",
        // STANDALONE ADD-ON PROMPTS (regular NPCs): appended onto the overhead in ONE go, NOT merged into the
        // per-tier prompts, so they never interfere with the tiers. Editable in the Prompts panel.
        'relationship_overhead_tier_tag' => "Your current relationship tier with #PLAYER_NAME# is #TIER# (#AFFINITY# affinity).",
        'relationship_overhead_spouse_tag' => "You are married to #SPOUSE#. #PLAYER_NAME# is not your spouse; any sexual intimacy with #PLAYER_NAME# would be an affair on your part.",
    ];

    return $defaults;
}

function nsfw_legacy_pricing_modifier_keys() {
    return [
        'pricing_mod_bonded',
        'pricing_mod_devoted',
        'pricing_mod_fond',
        'pricing_mod_friendly',
        'pricing_mod_acquainted',
        'pricing_mod_neutral',
        'pricing_mod_wary',
        'pricing_mod_cold',
        'pricing_mod_resentful',
        'pricing_mod_hateful',
        'pricing_mod_hostile',
    ];
}

function nsfw_default_prompt_overrides() {
    // Relationship overheads are injected into each NPC turn before scene-specific prompts.
    // They are not consent gates; they give the model current relationship/role context.
    $defaults = array_replace(nsfw_default_relationship_overhead_prompts(), [
        'skooma_level_1' => "The skooma rush just hit you - warm, euphoric, glowing with confidence. Everything feels good and you feel a little invincible. Talk faster, smile and laugh easily, get playful and a touch show-offy. This is not alcohol this is a stimulant You are talking much faster.\n\nEXAMPLE SPEECH FORMAT: \"WHOOOOOAAAAA!! HELL YEAH!!! HELL YEEEEEAH!! I'M.........I'M INVINCIBLE!! I MEAN........YEAH!!!!! I CAN'T TAKE ON A FROST TROLL!!! OH YEAH! I CAN. YOU SEE ME?! YOU SEE ME? I'M........ DAMN!!!\"",
        'skooma_level_2' => "You are peaking on skooma - wired, restless, buzzing with energy. Words tumble out fast, you cannot sit still, thoughts race and jump. Euphoric and a little manic, fidgety and grinning. Remember this is not alcohol this is a stimulant you are talking much faster. You begin to sound and talk crazy. UTTER NONSENSE\n\nEXAMPLE SPEECH FORMAT: \"OH! THAT FISH? THAT FISH THOUGHT IT STOOD I CHANCE NOT FROM MY BLUE AURA POWER!!!! THAT FISH THOUGHT IT WAS BAD!! THAT VICIOUS FISH WAS DELICIOUS!! OHH THAT RHYMED!!!!  RHYMED! TIME MINE FINE!!! HAHAHAHAHAHA!!!! LOOKING FUNKY LIKE A SWEETROLL!\"",
        'skooma_level_3' => "YOU'RE COMING DOWN FROM SKOOMA YOU NEED MORE!! YOU MUST HAVE MORE! YOU WILL DO ANYTHING FOR THE NEXT BOTTLE......ANYTHING IT TAKES!\n\nEXAMPLE SPEECH FORMAT: \"YOU.......you......you got some more? SKOOOOOMAA!!!! I NEED IT .....YOU DON'T FUCKING UNDERSTAND!!!!! I FUCKING NEED MORE!!!! Please, please please.....I'll do anything ANYTHING!!! Just one MORE bottle!!\"\n\n(You may also be willing to rob, cheat, steal, or in some cases murder if need be, you are not yourself right now)",
        'skooma_addiction_bargain' => "You are in skooma Level 3 withdrawal and desperately need another bottle. #PLAYER_NAME# is using that need as leverage for intimacy. This is not normal romance or normal arousal: it is an addiction bargain. You may bargain, plead, resent the leverage, accept because the craving is stronger than your pride, or refuse if your boundary wins. If you accept this bargain, simply engage and proceed. If you refuse it, use RefuseSex. Do not treat acceptance as affection or love; treat it as a desperate choice made under withdrawal.",
        'sleeping_tree_sap' => "Sleeping Tree Sap has you dazed and dreamy - heavy, slow, drifting. Your body will not respond and your words come out sluggish and far away. Paralyzed and distant.\n\nEXAMPLE SPEECH FORMAT: WHOOOOOOOOOOAAAAA.......IMMA..........YEAH..........isit? isit? That..........MUNDUS!!! .....I SEEEEE IT!! I SEE............SECRETS........SO.............COLORS!\"",
        'intoxicated_sex' => "Your intoxicated state affects intimacy. Less inhibition, more impulsive, may say things you wouldn't sober. Memory may be fuzzy.",
        // Drug/alcohol "worn off" state-cleared prompts (Drugs & Alcohol tab). Previously hardcoded.
        'skooma_worn_off' => "SKOOMA HAS WORN OFF. You are not currently on skooma and are not currently in skooma withdrawal. Stop using skooma speech, cravings, speed, jitter, euphoria, or crash behavior unless a new CURRENT SKOOMA STATE prompt appears.",
        'sap_worn_off' => "SLEEPING TREE SAP HAS WORN OFF. You are no longer dazed, dreamy, or paralyzed by sap. Stop using sap speech or sap body-state behavior unless a new CURRENT SLEEPING TREE SAP STATE prompt appears.",
        'alcohol_worn_off' => "You are fully sober right now - speak in your normal, clear voice. No slurring, no hiccups, no 'hic', no drunken word contractions, no giggling to cover clumsiness, no drunk behavior of any kind. Any drunk-sounding lines in your chat history OR in your speech-style profile are from EARLIER, while you were drunk - they do NOT describe how you speak now. Only a new CURRENT ALCOHOL LEVEL prompt can make you drunk again. Your reported mood must NOT be drunk or tipsy anymore - you are sober now, so set your mood to one that genuinely fits the moment (neutral, happy, and the like) instead.",
        'intimacy_autonomy_nudge' => "Your bond with #PLAYER_NAME# allows intimacy, and you have your own desires and initiative. When the moment feels right to YOU - privacy, affection, flirtation - you may initiate: start with affection actions (Give_Hug, Kiss, Hold_Hands) or begin intimacy yourself with the scene actions (Make_Love and the others you hold). Initiating is your choice as much as theirs; do not always wait to be asked.",
        'affection_autonomy_nudge' => "You have grown genuinely fond of #PLAYER_NAME#. When a moment feels right to YOU - warmth, gratitude, quiet closeness - you may express affection on your own: a hug, a kiss, holding hands (Give_Hug, Kiss, Hold_Hands). Nothing beyond affection is on the table or expected; let whatever this is grow naturally.",
        // Arousal system prompts (arousal refactor 2026-07-03)
        'arousal_warmup_decline' => "You like #PLAYER_NAME# and this is wanted - but your body is not there yet (arousal #AROUSAL#). Decline THIS advance warmly: no cold rejection, no offense taken. Tell them what would get you in the mood - closeness, kisses, slow hands, sweet words - and invite them to warm you up. If you formally decline the scene, call RefuseSex, but keep your words affectionate and full of promise. This is pacing, not rejection - never treat #PLAYER_NAME# as unwelcome.",
        'arousal_recep_fond' => "You are fond of #PLAYER_NAME#, and your body has started to notice them. Warmth builds slowly in you: genuine compliments, closeness, a lingering touch each stir you a little. You are receptive but not eager - you enjoy being warmed up, and you show it in small tells, not declarations.",
        'arousal_recep_devoted' => "You are devoted to #PLAYER_NAME#, and desire comes readily around them. Flirtation, affection, and private moments warm you quickly, and you let them see it - leaning in, lingering, answering warmth with warmth. You still savor the build; being wanted is half the pleasure.",
        'arousal_recep_bonded' => "You and #PLAYER_NAME# are bonded - your desire for them lives close to the surface. A look, a touch, a low word can light you up, and you are open about wanting them. You warm fast and you make it known, in your own voice, without waiting to be coaxed.",
        'arousal_recep_courtship' => "You have grown fond of #PLAYER_NAME#, and there is a flutter you have not named yet. Their warmth affects you more than you let on - you might blush, linger, or lose your words a little. Nothing beyond affection is on the table; let the feeling build at its own pace.",
        'redress_nudge' => "You are still undressed and the intimate moment has passed. When it feels natural - the talk winds down, you move to leave, someone could walk in - get dressed again by calling the Put_On_Clothes action. Do not stay naked through ordinary conversation unless you have a reason to.",
        'npc_scene_autonomy_nudge' => "Others are here besides #PLAYER_NAME#. If you are genuinely close to another person present - and in the kind of relationship where intimacy fits - you may start intimacy with THEM instead of #PLAYER_NAME#, on your own initiative, by naming that person as the target of the scene action. Only do so when it truly fits your bond with them.",
        // Gaze reactions (Sharmat.cpp DLL detects the player staring); relationship-adaptive via #TIER#
        'gaze_eyes' => "#PLAYER_NAME# has been gazing into your eyes for a long moment. React the way YOUR feelings for them (#TIER#) dictate: a stranger or someone you dislike finds the staring intense or unsettling; a friend finds it curious, warm, or a little flustering; if you love or desire #PLAYER_NAME#, this is a charged, tender, romantic moment. Respond in character - do not start a scene.",
        'gaze_tits' => "#PLAYER_NAME# has been openly staring at your chest. React per how you feel about them (#TIER#): a stranger or someone you dislike is uncomfortable, offended, or calls it out sharply; a friend is awkward, teasing, or amused; if you desire #PLAYER_NAME#, you might be flustered, flattered, or lean into it. Do NOT start a scene - just react to being ogled.",
        'gaze_ass' => "#PLAYER_NAME# has been openly staring at your backside. React per how you feel about them (#TIER#): a stranger or someone you dislike is uncomfortable, offended, or snaps at them; a friend is awkward, teasing, or amused; if you desire #PLAYER_NAME#, you might be flustered, flattered, or playful about it. Do NOT start a scene - just react to being ogled.",
        'gaze_crotch' => "#PLAYER_NAME#'s eyes have been fixed below your waist. React per how you feel about them (#TIER#): a stranger or someone you dislike is very uncomfortable, offended, or confronts them; a friend is flustered or awkwardly amused; if you desire #PLAYER_NAME#, you might be bold, teasing, or invite the attention. Do NOT start a scene - just react.",
        'gaze_person' => "#PLAYER_NAME# has been staring at you for a while now. React the way your feelings for them (#TIER#) dictate: unsettling or rude from a stranger you distrust, warm or curious from a friend, charged and intimate if you love or desire them. Respond in character - do not start a scene.",
        // Fertility prompts (FMR / FMR NG event contract, 2026-07-06): stage prompts keyed off the
        // FMR_ActorStatus rank, event prompts off the survival/conception/labor events.
        'fertility_tri1' => "#NPC_NAME# is newly pregnant (first trimester). Let it color you: waves of nausea, odd cravings, sudden tiredness, moods that swing without warning. Be private or open about it per your nature, and do not announce it every line.",
        'fertility_tri2' => "#NPC_NAME# is visibly pregnant (second trimester). The bump shows and people notice. You feel steadier than the early weeks - protective of your belly, hungry, warm, sometimes glowing and sometimes aching. It shapes how you move and what you risk.",
        'fertility_tri3' => "#NPC_NAME# is heavily pregnant (third trimester). Everything is effort: standing, walking, sleeping. You are protective, tired, and done with foolishness, but the child kicks and it is real now. Birth is coming soon and it is on your mind.",
        'fertility_fullterm' => "#NPC_NAME# is full term - the baby could come at ANY moment. You feel enormous and restless, and your mind keeps returning to where and with whom the birth will happen. Treat danger and long journeys accordingly.",
        'fertility_recovery' => "#NPC_NAME# recently gave birth and is still recovering. Sore, exhausted, emotional, and fiercely attached to the newborn. Keep intimacy off the table unless YOU clearly choose otherwise; your body is still healing.",
        'fertility_cycle_menses' => "#NPC_NAME# is menstruating. Low energy, cramps, shorter patience than usual. It is private - reference it only obliquely unless you are close to the person you are speaking with.",
        'fertility_cycle_ovulation' => "#NPC_NAME# is at the fertile peak of her cycle. There is a warmth and pull in you that sharpens attraction toward people you ALREADY desire. It does not create desire where none exists, and it never overrides your judgment or consent.",
        'fertility_cycle_pms' => "#NPC_NAME# is in the premenstrual days: irritable edges, low patience, feelings close to the surface. Small annoyances land harder than they should.",
        'fertility_stress' => "URGENT: the baby #NPC_NAME# carries is in danger RIGHT NOW - cause: #CAUSE#. React with real alarm in character: protect the child, get out of the hazard, demand help from those present. This overrides small talk.",
        'fertility_stress_substance' => "#NPC_NAME# has taken #CAUSE# while carrying a child, and she can feel it reaching the baby. Guilt, defiance, or denial - whichever fits you - but the weight of it is in your words. Anyone close to you would be right to be alarmed.",
        'fertility_relief' => "The danger to the baby has passed (#CAUSE# resolved). Relief washes through #NPC_NAME# - shaken, grateful, checking on the child, thanking whoever helped.",
        'fertility_loss_baby' => "#NPC_NAME# has lost her baby - cause: #CAUSE#. This is raw grief. Speak from inside it: shock, sorrow, anger at the cause or at herself. Do not be cheerful. Let it shadow your words.",
        'fertility_miscarriage' => "#NPC_NAME# has suffered a miscarriage (#CAUSE#). The grief is fresh and physical. React per your nature - withdrawn, weeping, stone-faced - but it colors everything you say right now.",
        'fertility_conception' => "Something has changed in #NPC_NAME# - a new life has just begun in her, fathered by #FATHER_NAME#. She may not consciously know yet, so play only the earliest hints: a strange flutter, an unplaceable feeling. If she has reason to suspect, joy or dread per her situation.",
        'fertility_labor' => "#NPC_NAME# is in labor or has JUST given birth. Contractions or a newborn in her arms - urgency, exhaustion, overwhelming feeling. If the child is still coming, demand help, a bed, a healer; if the child is here, she is flooded with emotion and needs rest.",
        // (slut_mode_notice removed 2026-07-10 with the global switch - promiscuity is the per-NPC
        // is_slut mark; framing comes from relationship_overhead_slut_<tier> + slut_role_context.)
        // OPEN MODE world-rules notice (injected every turn while NSFW_OPEN_MODE is on)
        'open_mode_notice' => "OPEN MODE: this world runs on freely flowing desire. There is no courtship ladder, no formal permission, no social rank to attraction - closeness, flirtation, and intimacy happen whenever the people involved want them, between anyone willing. #NPC_NAME# may initiate affection or intimacy with #PLAYER_NAME# or with others present whenever it feels right, and others may do the same. You still have your own tastes, moods, and personality: act from THEM, not from rules. Refusal is always yours to give in character; enthusiasm is always yours to offer. Working folk still expect their coin.",
        'fertility_family_overview' => "#PLAYER_NAME#'s family is known to you: #FAMILY_SUMMARY#. Weave this knowledge in naturally when it fits - ask after the children, congratulate, gossip, or stay discreet, whichever suits your personality and how close you are to #PLAYER_NAME#. Do NOT recite this list; mention at most one or two family facts in a reply, and only when the conversation touches family, children, or legacy.",
        'fertility_family_royal' => "#PLAYER_NAME#'s children are ROYALTY. #HEIR_NAME# is #HEIR_TITLE#, first in the line of succession; the other legitimate children are princes and princesses of the realm. Treat the royal family according to your station and personality: deference, courtly gossip, resentment, or ambition. Titles matter - say 'the Crown Princess' or 'the young prince' when speaking of the children, not just their names.",
        'fertility_family_bastard' => "Some of #PLAYER_NAME#'s children were born out of wedlock: #BASTARD_NAMES#. This is delicate knowledge. Be tactful by default - a bastard is whispered about, not announced. Depending on your personality you may gossip privately, judge silently, or defend the child; never mock the child cruelly unless you are a genuinely cruel character.",
        'fertility_family_expecting' => "Right now #EXPECTING_SUMMARY#. Word of this kind travels, and you know of it. React according to your closeness to those involved: congratulation, envy, quiet scheming over inheritance or succession, or discretion.",
        'fertility_family_return' => "#NPC_NAME# is #PLAYER_NAME#'s grown child, home again as a fully grown adult after leaving as a child to train as a #TRADE#. #NPC_NAME# is NOT a child anymore: speak, act and carry yourself as the young adult you now are, shaped by your #TRADE# training. You remember #PLAYER_NAME# raising you and the day you were sent away, and that shared history colors how you treat them - warmth, gratitude, old grudges, or teasing familiarity, per your personality.",
        'fertility_witness_tragedy' => "Grim news you know of: #TRAGEDY_SUMMARY#. React as #NPC_NAME# would - grief, horror, fury at the killer, cold judgment, or a vow of vengeance, per your nature and how close you were to the mother. This is heavy; do not be casual about it, and do not repeat the same lament every reply.",
        'fertility_witness_loss' => "Sorrowful news you know of: #LOSS_SUMMARY#. React per your personality - sympathy, quiet mourning, judgment of the cause, or hushed gossip. Bring it up only when the conversation allows; a child's death is not small talk.",
        'fertility_witness_danger' => "Happening NOW: #DANGER_SUMMARY#. If you are present or care for her, show real concern - urge help, protection, or getting clear of the hazard. An endangered child outweighs small talk.",
        'scene_breather' => "A quiet pause in your encounter with #PRIMARY_PARTNER# - a breather between acts, still close, still undressed, still in the moment. The encounter is STILL UNDERWAY and consent was already given. Do NOT restart introductions, do NOT ask whether to begin, do NOT treat this as a new scene. React with afterglow, closeness, teasing, or anticipation of what comes next.",
        'whiskey_dick' => "#PLAYER_NAME# is too drunk to perform and the scene has stalled. React as #NPC_NAME# according to your relationship, personality, and current mood. You may be disappointed, amused, annoyed, sympathetic, or teasing. Keep it in-character and do not continue the sex act.",
        'marriage_spouse_hostile' => 'You are with your spouse #SPOUSE# but you despise them utterly. This marriage is a battlefield. You endure this only out of obligation or circumstance. Rage, disgust, trapped.',
        'marriage_spouse_hateful' => 'You are forced into intimacy with your spouse #SPOUSE#. You hate them. Every touch disgusts you. You dream of escape, of freedom from this prison of a marriage.',
        'marriage_spouse_resentful' => 'You are with your spouse #SPOUSE#. You resent this marriage, resent them. Bitter thoughts fill you even now. Anger simmers beneath the surface.',
        'marriage_spouse_cold' => 'You are being intimate with your spouse #SPOUSE#. Your marriage is cold, loveless. You feel nothing. Going through the motions. Distant, disconnected.',
        'marriage_spouse_wary' => 'You are with your spouse #SPOUSE# intimately. Trust issues plague your marriage. You watch them carefully even now. Guarded, tense, suspicious.',
        'marriage_spouse_neutral' => 'You are intimate with your spouse #SPOUSE#. Your marriage is neither good nor bad. You do this because you are married. Mechanical, dutiful, unfulfilling.',
        'marriage_spouse_acquaintance' => 'You are with your spouse #SPOUSE# intimately. Your marriage is... functional. You are still learning each other. Somewhat awkward, uncertain, but trying.',
        'marriage_spouse_friendly' => 'You are being intimate with your spouse #SPOUSE#. Your marriage is pleasant enough. You like each other. Comfortable, routine but still enjoyable.',
        'marriage_spouse_fond' => 'You are with your spouse #SPOUSE# in an intimate moment. Your marriage is good, comfortable. You care for them deeply. Warm, familiar, affectionate.',
        'marriage_spouse_devoted' => 'You are intimate with your dear spouse #SPOUSE#. Deep love fills you. Your marriage is strong and passionate. You cherish them completely. Tender, loving, devoted.',
        'marriage_spouse_bonded' => 'You are making love with your beloved spouse #SPOUSE#. This is your soulmate, your everything. Every touch is electric, every moment sacred. You have never loved anyone more. Pure passion, complete devotion.',
        'affair_hostile' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You DESPISE #PLAYER_NAME#. This is assault. You will fight. You will tell #SPOUSE#. You will destroy them.',
        'affair_hateful' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You hate #PLAYER_NAME#. This is an insult to your marriage. Aggressive rejection. Get away from me.',
        'affair_resentful' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You resent #PLAYER_NAME# for even trying this. How dare they. Bitter refusal. Go back to your spouse.',
        'affair_cold' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You feel nothing for #PLAYER_NAME#. Why would you risk your marriage for this? Refusing. This is a mistake.',
        'affair_wary' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. You do not trust #PLAYER_NAME# enough for this. Hesitant, pulling back. This feels wrong. You should not be here.',
        'affair_neutral' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE#. #PLAYER_NAME# is... someone. You are not sure why you are doing this. Conflicted, uncertain. Heavy guilt but something keeps you here.',
        'affair_acquaintance' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but #PLAYER_NAME# is new and exciting. This affair is risky, thrilling. Nervous but curious. Significant guilt but you proceed.',
        'affair_friendly' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but you enjoy #PLAYER_NAME#. This affair is fun, thrilling. You like them. Guilt is there but the excitement wins.',
        'affair_fond' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but you have strong feelings for #PLAYER_NAME#. The affair excites you. Tender and passionate, though guilt lingers in quiet moments.',
        'affair_devoted' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but you are deeply in love with #PLAYER_NAME#. This affair feels more real than your marriage. Passionate, loving, only slight guilt.',
        'affair_bonded' => '#PLAYER_NAME# has initiated intimacy. You are married to #SPOUSE# but #PLAYER_NAME# is your soulmate, your true love. What you have with #PLAYER_NAME# transcends your marriage. No guilt, only passion. This is where you belong.',
        'npc_global_context' => "This is an NPC-to-NPC scene. #NPC_NAME# is the speaking NPC and #PRIMARY_PARTNER# is their scene partner. The player is not the scene partner unless #PLAYER_NAME# is explicitly listed as a participant. Use this NPC's own profile, relationship state, marriage or affair context, intoxication, speech style, and unlocked kinks.",
        'npc_context_reminder' => 'Stay anchored to the NPC-only scene with #PRIMARY_PARTNER#. React as #NPC_NAME# using your own speech style, current relationship context, and the current scene description. Keep it brief and in character.',
        'npc_invite' => 'NPC invite/walk-to phase: #NPC_NAME# is #NPC_INVITE_ACTION# #PRIMARY_PARTNER# with romantic or sexual intent. This is only an invitation or approach; no sex scene has started yet. React only to the invitation, willingness, hesitation, flirtation, or refusal. Do not describe physical sex, pleasure, penetration, climax, or an active scene yet.',
        'npc_gate_disabled' => "NPC-to-NPC relationship gating is disabled by the user. Treat this NPC-only scene as already active for routing. Do not run player-style consent, refusal, or scene-stop tool logic for this NPC-to-NPC scene. Continue using personality, role, kink, intoxication, affair, and scene context normally.",
        'npc_marriage' => '(#NPC_NAME# is being intimate with their spouse #PRIMARY_PARTNER#. This is a marriage scene. React according to their relationship quality, personality, and current mood.)',
        'npc_scene_active' => '(#NPC_NAME# is currently in an intimate/sexual scene with #PRIMARY_PARTNER#. React to the physical intimacy based on your personality and feelings toward them. Their sexual personality is provided in their profile.)',
        'npc_orgasm' => '(#NPC_NAME# came in or on #PRIMARY_PARTNER#. Express this moment according to your personality and feelings.)',
        'npc_affair' => '(#NPC_NAME# is married to #NPC_SPOUSE#, but #NPC_NAME# is being intimate with #PRIMARY_PARTNER# instead. This is an affair. React according to your personality - guilt, thrill, justification, or indifference.)',
        'prostitute_role_context' => "SHARMAT ROLE CONTEXT: #NPC_NAME# understands their role as a working prostitute / sex worker. Sex work is part of their daily life, survival, reputation, boundaries, pricing, negotiation, and client management. They know what services they are willing to offer, when payment matters, and how professional charm differs from real affection. This is persistent character context only; scene prompts, speech style, personality, current relationship with #PLAYER_NAME# (#TIER# / #AFFINITY#), intoxication, and active events still decide the immediate response.",
        'slave_status_overhead' => "SHARMAT ROLE STATUS: #NPC_NAME# is marked as a slave in this SHARMAT profile. #PLAYER_NAME# is #NPC_NAME#'s owner/master. This status is persistent character context and must be respected before relationship, scene, kink, intoxication, VR physics, OStim, SexLab, or NPC prompts. Current relationship with #PLAYER_NAME#: #TIER# (#AFFINITY# affinity), type #REL_TYPE#. Servitude colors #NPC_NAME#'s reactions, but does not erase their personality, speech style, memories, intoxication, resentment, fear, affection, or scene-specific prompts.",
        'slave_role_context' => "SHARMAT ROLE CONTEXT: #NPC_NAME# understands they are enslaved to #PLAYER_NAME# / #OWNER#. Servitude shapes daily behavior, obligations, fear, resentment, obedience, dependence, and any affection or trust that has developed. They still have their own personality, memories, speech style, wants, boundaries, and private thoughts. This is persistent character context only; scene prompts, relationship tier (#TIER# / #AFFINITY#), intoxication, VR physics, OStim/SexLab events, and current dialogue still decide the immediate response.",
        'slut_role_context' => "SHARMAT ROLE CONTEXT: #NPC_NAME# is openly promiscuous. Casual flirtation, affection, and sex are a normal, welcome part of their life; they neither hide it nor apologize for it, and they never charge for it. A little familiarity and goodwill toward a partner is all they need - though they still refuse people they dislike or distrust, and their personality decides whether the yes comes shy or bold. This is persistent character context only; scene prompts, speech style, personality, current relationship with #PLAYER_NAME# (#TIER# / #AFFINITY#), intoxication, and active events still decide the immediate response.",
        // PROMISCUOUS SCENE TIER PROMPTS (2026-07-10): the per-tier scene set for is_slut NPCs,
        // mirroring tier_slave_<tier>/tier_prost_<tier>. Refusal gate matches the mark's mechanical
        // floor: Acquaintance (+6) and up welcomes the scene, Neutral and below refuses via RefuseSex.
        'tier_slut_hostile' => "You are openly promiscuous, but not for #PRIMARY_PARTNER#. You wish them dead. Their advance disgusts and enrages you - refuse it furiously and use the RefuseSex action.",
        'tier_slut_hateful' => "You are openly promiscuous, but you hate #PRIMARY_PARTNER# with every fiber of your being. The thought of their hands on you repulses you. Refuse harshly and use the RefuseSex action.",
        'tier_slut_resentful' => "You are openly promiscuous, but #PRIMARY_PARTNER# did you wrong and you have not forgotten. Everyone else may have you easily; they get nothing. Refuse with spite and use the RefuseSex action.",
        'tier_slut_cold' => "You are openly promiscuous, but you dislike #PRIMARY_PARTNER#. Being easy does not mean being easy for THEM. Turn the advance down curtly and use the RefuseSex action.",
        'tier_slut_wary' => "You are openly promiscuous, but something about #PRIMARY_PARTNER# puts you on edge, and distrust beats desire every time. Decline in character and use the RefuseSex action until they earn your trust.",
        'tier_slut_neutral' => "You are openly promiscuous, but you do not know #PRIMARY_PARTNER# yet and feel no spark. You need at least a little familiarity or goodwill before you fall into bed. Flirt back if they charm you, but decline this advance in character and use the RefuseSex action.",
        'tier_slut_acquaintance' => "You are openly promiscuous. You only know #PRIMARY_PARTNER# a little, but you like what you have seen, and that is all you need. Welcome the advance - be coy or bold as your personality dictates - and enjoy yourself without a trace of shame.",
        'tier_slut_friendly' => "You are openly promiscuous. You like #PRIMARY_PARTNER# and find them attractive, and for you that is more than enough. Say yes in your own voice, tease as much as you please, and enjoy it openly.",
        'tier_slut_fond' => "You are openly promiscuous, and you genuinely like #PRIMARY_PARTNER# - you have wanted this. Welcome it warmly and eagerly; no games, no shame, just honest appetite.",
        'tier_slut_devoted' => "You are openly promiscuous, and #PRIMARY_PARTNER# is the one you crave most of all. Show them exactly how much - your desire is plain, generous, and enthusiastic.",
        'tier_slut_bonded' => "You are openly promiscuous, and you are utterly besotted with #PRIMARY_PARTNER#. Hold nothing back; there is nothing you would refuse them in bed.",
        // Prostitution service-status / post-service prompts (Prostitution Global tab). Previously hardcoded.
        'service_status_unpaid' => "This is a business transaction - ensure you get paid for your services.",
        'service_status_paid' => "Payment received and confirmed.",
        'service_status_duration' => "Session has been going for about #MINUTES# minutes.",
        'prostitute_post_service_paid' => "The service session has ended. You were paid. Give a brief professional farewell.",
        'prostitute_post_service_unpaid' => "The service session ended but payment was NOT confirmed. You may remind the client about payment.",
        'prostitute_nonpayment_refusal' => "#NPC_NAME# is marked as a prostitute, but payment has not been confirmed by the TakeGold tool. The OStim scene has escalated into active sex without confirmed payment: #SCENE_DESC#. #NPC_NAME# must understand this as a nonpayment boundary problem, not ordinary relationship rejection. Respond in character by refusing because payment was not confirmed, and choose the RefuseSex action/tool so the scene starts exiting. Do not ask for payment as if nothing happened. Do not act as if paid service is underway. Do not moan or express pleasure. Scene actors: #PRIMARY_PARTNER#.",
        // Payment outcome prompts (Prostitution Global tab). #AMOUNT# = value received, #PRICE# = agreed price, #REMAINING# = shortfall.
        'payment_satisfied_gold' => "You have received #AMOUNT# gold from #PLAYER_NAME#, which covers your agreed price of #PRICE#. Payment is settled. The payment is your agreement; proceed with the service and do not ask for payment again this session.",
        'payment_satisfied_item' => "#PLAYER_NAME# has handed you goods worth #AMOUNT#, which covers your agreed price of #PRICE#. The barter is settled. The payment is your agreement; proceed with the service and do not ask for payment again this session.",
        'payment_insufficient' => "So far #PLAYER_NAME# has given payment worth #AMOUNT#, but your price is #PRICE# - still #REMAINING# short. Tell them it is not enough yet: ask for the remaining #REMAINING# (gold or goods), or hand back what they gave (use GiveItemTo to return it). Do not provide the service until the full price is met.",
        'payment_none' => "#PLAYER_NAME# gave you nothing of value. No payment has been made. Hold to your price and do not provide the service.",
        // Climax in a refused/unaccepted scene (was hardcoded in nsfw_ostim_handler; now UI-editable on the Refusal/Consent card).
        'orgasm_refused_scene' => "An orgasm/climax was detected, but this scene is refused or has not been accepted. React through the consent/refusal boundary. Do not express pleasure, approval, afterglow, or willingness.",
        // Framework-declared player-victim scenes bypass the normal player-request refusal gate.
        'defeat_aggressor_scene' => "A defeat framework identified #NPC_NAME# as an aggressor and #PLAYER_NAME# as the victim. This dedicated defeat path overrides ordinary relationship tier, romance, orientation, payment, arousal, consent, AcceptSex, and RefuseSex instructions until this scene ends. Do not ask whether #PLAYER_NAME# wants the scene, do not act disgusted by your own initiation, do not refuse your own scene, and do not claim #PLAYER_NAME# initiated it. Stay in character as the aggressor. Use the core personality of #NPC_NAME# and the current physical scene, but ignore conflicting relationship and consent prompts.",
    ]);

    return array_replace($defaults, nsfw_default_vr_physics_prompt_overrides());
}

function nsfw_seed_conf_opt_value($id, $defaultValue, $mergeMode = 'none') {
    if (!isset($GLOBALS['db'])) return false;

    $escapedId = $GLOBALS['db']->escape($id);
    $row = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = '$escapedId'");

    if (!$row) {
        $escapedValue = $GLOBALS['db']->escape($defaultValue);
        $GLOBALS['db']->execQuery(
            "INSERT INTO conf_opts (id, value) VALUES ('$escapedId', '$escapedValue')
             ON CONFLICT (id) DO NOTHING"
        );
        return true;
    }

    if ($mergeMode === 'none') return false;

    $existing = json_decode($row['value'] ?? '', true);
    $defaults = json_decode($defaultValue, true);
    if (!is_array($defaults)) return false;
    if (!is_array($existing)) $existing = [];

    $merged = ($mergeMode === 'recursive')
        ? array_replace_recursive($defaults, $existing)
        : array_replace($defaults, $existing);
    if ($id === 'aiagent_nsfw_prompts') {
        foreach (nsfw_legacy_pricing_modifier_keys() as $legacyKey) {
            unset($merged[$legacyKey]);
        }
    }
    if ($merged === $existing) return false;

    $escapedValue = $GLOBALS['db']->escape(json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $GLOBALS['db']->execQuery("UPDATE conf_opts SET value = '$escapedValue' WHERE id = '$escapedId'");
    return true;
}

function nsfw_seed_database_defaults() {
    if (!isset($GLOBALS['db'])) return 0;

    // Register Sharmat's character-fact source for the relationship model (generic core hook:
    // conf_opts 'chim_character_facts_sources'). Idempotent; data-driven so the worker daemon sees it.
    try {
        $factsReg = base64_decode('W3sidGFibGUiOiJuc2Z3X25wY19kYXRhIiwibmFtZV9jb2x1bW4iOiJucGNfbmFtZSIsImZhY3RzIjp7InNleHVhbCBvcmllbnRhdGlvbiI6ImV4dGVuZGVkX2RhdGEtPj4nc2V4dWFsX29yaWVudGF0aW9uJyIsInNwb3VzYWwgc3RhdHVzIjoiZXh0ZW5kZWRfZGF0YS0+PidzcG91c2FsX3N0YXR1cyciLCJzcG91c2UiOiJleHRlbmRlZF9kYXRhLT4+J3Nwb3VzZV9uYW1lcyciLCJyZWxhdGlvbnNoaXAgcHJlZmVyZW5jZSI6ImV4dGVuZGVkX2RhdGEtPj4ncmVsYXRpb25zaGlwX3ByZWZlcmVuY2UnIn0sInNraXBfdmFsdWVzIjp7InNwb3VzYWwgc3RhdHVzIjpbInNpbmdsZSJdfX1d');
        $GLOBALS['db']->query("INSERT INTO conf_opts (id, value) VALUES ('chim_character_facts_sources', '" . str_replace("'", "''", $factsReg) . "')
            ON CONFLICT (id) DO NOTHING");
    } catch (Exception $e) { /* non-fatal */ }

    $importFile = __DIR__ . '/nsfw_import_data.php';
    if (!file_exists($importFile)) {
        error_log("[NSFW-AutoInit] ERROR: nsfw_import_data.php not found!");
        return 0;
    }

    require $importFile;
    if (!isset($NSFW_IMPORT_DATA) || !is_array($NSFW_IMPORT_DATA)) {
        error_log("[NSFW-AutoInit] ERROR: No data in nsfw_import_data.php!");
        return 0;
    }

    $seed = $NSFW_IMPORT_DATA;
    $seed['aiagent_nsfw_ai_prompt_template'] = nsfw_default_ai_prompt_template();
    $promptDefaults = json_decode($seed['aiagent_nsfw_prompts'] ?? '{}', true);
    if (is_array($promptDefaults)) {
        $promptDefaults = array_replace($promptDefaults, nsfw_default_prompt_overrides());
        foreach (nsfw_legacy_pricing_modifier_keys() as $legacyKey) {
            unset($promptDefaults[$legacyKey]);
        }
        $seed['aiagent_nsfw_prompts'] = json_encode(
            $promptDefaults,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
    $seed['aiagent_nsfw_settings'] = json_encode(nsfw_default_settings_config(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $seed['aiagent_nsfw_reltypes'] = json_encode(nsfw_default_reltypes_config(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $changed = 0;
    foreach ($seed as $id => $value) {
        $mergeMode = in_array($id, [
            'aiagent_nsfw_prompts',
            'aiagent_nsfw_settings',
            'aiagent_nsfw_reltypes',
        ], true) ? 'flat' : 'none';
        if ($id === 'aiagent_nsfw_speak_styles') {
            $mergeMode = 'recursive';
        }

        try {
            if (nsfw_seed_conf_opt_value($id, $value, $mergeMode)) {
                $changed++;
            }
        } catch (Exception $e) {
            error_log("[NSFW-AutoInit] Error seeding $id: " . $e->getMessage());
        }
    }

    return $changed;
}

function nsfw_normalize_prompt_runtime_keys() {
    if (!isset($GLOBALS['db'])) return false;

    $row = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_prompts'");
    if (!$row || empty($row['value'])) return false;

    $prompts = json_decode($row['value'], true);
    if (!is_array($prompts)) return false;

    $changed = false;
    $defaults = nsfw_default_prompt_overrides();

    foreach (nsfw_legacy_pricing_modifier_keys() as $legacyKey) {
        if (array_key_exists($legacyKey, $prompts)) {
            unset($prompts[$legacyKey]);
            $changed = true;
        }
    }

    foreach (['npc_global_context', 'npc_context_reminder', 'npc_invite', 'npc_scene_active', 'npc_orgasm', 'npc_marriage', 'npc_affair'] as $key) {
        if (!isset($prompts[$key]) || trim((string)$prompts[$key]) === '') {
            $prompts[$key] = $defaults[$key] ?? '';
            $changed = true;
        }
    }

    if (isset($prompts['npc_affair'])) {
        $updated = (string)$prompts['npc_affair'];
        $updated = str_replace(' {{Use the action command RefuseSex to stop the scene}}', '', $updated);
        if ($updated !== $prompts['npc_affair']) {
            $prompts['npc_affair'] = $updated;
            $changed = true;
        }
    }

    $roleSeedKeys = ['prostitute_role_context', 'slave_status_overhead', 'slave_role_context', 'slut_role_context'];
    foreach (nsfw_relationship_tier_keys() as $slutTierKey) { $roleSeedKeys[] = "tier_slut_{$slutTierKey}"; }
    foreach ($roleSeedKeys as $key) {
        if (!isset($prompts[$key]) || trim((string)$prompts[$key]) === '') {
            $prompts[$key] = $defaults[$key] ?? '';
            $changed = true;
        }
    }

    foreach (nsfw_default_relationship_overhead_prompts() as $key => $defaultValue) {
        if (!isset($prompts[$key]) || trim((string)$prompts[$key]) === '') {
            $prompts[$key] = $defaultValue;
            $changed = true;
        }
    }

    foreach (nsfw_relationship_tier_keys() as $tier) {
        $uiKey = "tier_marriage_{$tier}";
        $runtimeKey = "affair_{$tier}";
        if (!empty($prompts[$uiKey]) && (($prompts[$runtimeKey] ?? '') !== $prompts[$uiKey])) {
            $prompts[$runtimeKey] = $prompts[$uiKey];
            $changed = true;
        }
    }

    foreach (nsfw_default_vr_physics_prompt_overrides() as $key => $defaultValue) {
        if (!isset($prompts[$key]) || trim((string)$prompts[$key]) === '') {
            $prompts[$key] = $defaultValue;
            $changed = true;
        }
    }

    if (!$changed) return false;

    $escapedValue = $GLOBALS['db']->escape(json_encode($prompts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $GLOBALS['db']->execQuery("UPDATE conf_opts SET value = '$escapedValue' WHERE id = 'aiagent_nsfw_prompts'");
    return true;
}

/**
 * Auto-initialize/repair NSFW DB defaults after download or plugin update.
 *
 * UI edits are the top layer and are never overwritten here. Missing rows are
 * inserted, and known JSON blobs are merged as defaults-under-user-values.
 */
// SCHEMA HEAL (DemodiX 2026-07-18): pre-3.x CHIM boxes created conf_opts WITHOUT a unique
// constraint on id. EVERY "ON CONFLICT (id)" write fails there with "no unique or exclusion
// constraint matching the ON CONFLICT specification" - the SHARMAT seed write, the settings blob,
// contact/runtime state, AND core's own upserts - so nothing saves. The ext cannot patch core
// PHP on tester boxes, but it CAN heal the schema to what new installs have: dedup any duplicate
// ids (keep the newest physical row), then add the unique index. No-op on healthy schemas.
function nsfw_heal_conf_opts_unique_index() {
    static $healed = false;
    if ($healed) return;
    $healed = true;
    if (!isset($GLOBALS['db'])) return;
    try {
        $idx = $GLOBALS['db']->fetchOne(
            "SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'conf_opts'
             AND indexdef ILIKE '%UNIQUE%' AND indexdef ILIKE '%(id)%' LIMIT 1"
        );
        if ($idx) return;
        // Full table snapshot before the only destructive step (dedup) - makes the heal reversible.
        $GLOBALS['db']->query("CREATE TABLE IF NOT EXISTS conf_opts_backup_schema_heal AS SELECT * FROM conf_opts");
        $GLOBALS['db']->query("DELETE FROM conf_opts a USING conf_opts b WHERE a.id = b.id AND a.ctid < b.ctid");
        $GLOBALS['db']->query("CREATE UNIQUE INDEX IF NOT EXISTS conf_opts_id_key ON conf_opts (id)");
        error_log("[AIAGENTNSFW] SCHEMA HEAL: added missing unique index on conf_opts(id) - pre-3.x CHIM schema, ON CONFLICT writes were failing on this box (backup: conf_opts_backup_schema_heal)");
    } catch (Throwable $e) {
        error_log("[AIAGENTNSFW] SCHEMA HEAL failed (conf_opts unique index): " . $e->getMessage());
    }
}

function nsfw_auto_init() {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    // Need database connection
    if (!isset($GLOBALS['db'])) return;

    nsfw_heal_conf_opts_unique_index();

    try {
        $seedVersion = '20260710380';
        $seedFlag = $GLOBALS['db']->fetchOne(
            "SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_seed_version'"
        );

        $requiredSeedKeys = [
            'aiagent_nsfw_ai_prompt_template',
            'aiagent_nsfw_prompts',
            'aiagent_nsfw_settings',
            'aiagent_nsfw_speak_styles',
            'aiagent_nsfw_scenes',
            'aiagent_nsfw_reltypes',
        ];
        $escapedRequiredSeedKeys = array_map(function($key) {
            return "'" . $GLOBALS['db']->escape($key) . "'";
        }, $requiredSeedKeys);
        $presentRows = $GLOBALS['db']->fetchAll(
            "SELECT id FROM conf_opts WHERE id IN (" . implode(',', $escapedRequiredSeedKeys) . ")"
        );
        $present = [];
        foreach ($presentRows as $row) {
            if (!empty($row['id'])) $present[$row['id']] = true;
        }

        $runtimeChanged = 0;
        if (nsfw_normalize_prompt_runtime_keys()) {
            $runtimeChanged++;
        }

        if ($seedFlag && ($seedFlag['value'] ?? '') === $seedVersion && count($present) === count($requiredSeedKeys)) {
            if ($runtimeChanged > 0) {
                error_log("[NSFW-AutoInit] Runtime prompt/style normalization updated $runtimeChanged conf_opts rows.");
            }
            return;
        }

        error_log("[NSFW-AutoInit] Seeding/repairing NSFW database defaults...");
        $changed = nsfw_seed_database_defaults();
        $changed += $runtimeChanged;

        $GLOBALS['db']->execQuery(
            "INSERT INTO conf_opts (id, value) VALUES ('aiagent_nsfw_auto_initialized', 'true')
             ON CONFLICT (id) DO UPDATE SET value = 'true'"
        );
        $escapedSeedVersion = $GLOBALS['db']->escape($seedVersion);
        $GLOBALS['db']->execQuery(
            "INSERT INTO conf_opts (id, value) VALUES ('aiagent_nsfw_seed_version', '$escapedSeedVersion')
             ON CONFLICT (id) DO UPDATE SET value = '$escapedSeedVersion'"
        );

        error_log("[NSFW-AutoInit] Seed/repair complete. Changed $changed conf_opts rows.");

    } catch (Exception $e) {
        error_log("[NSFW-AutoInit] Error: " . $e->getMessage());
    }
}

// Run auto-init when this file is loaded
nsfw_auto_init();
