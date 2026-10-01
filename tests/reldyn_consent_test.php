<?php

/**
 * Regression harness for the LOCAL RelDyn consent hook (branch reldyn-consent).
 *
 * Loads the real reldyn_consent_policy.php and the real, edited aiagentNsfwRelTypeSexEligible() out of common.php (extracted with the
 * tokenizer: common.php itself needs the whole CHIM bootstrap) against a fixture: a fake database handle holding the config rows and
 * the plugin_extended_data RelDyn publishes, and stand-ins for Sharmat's own helpers. The published fixtures are the payloads
 * RelDynConsent::payload() produces (captured from the RelDyn side), one per stance.
 *
 * Run: php tests/reldyn_consent_test.php
 */

$failures = [];
$assertions = 0;
function checkRelDynConsent($condition, $message)
{
    global $failures, $assertions;
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
}

// ---------------------------------------------------------------- the fixture world

ini_set('error_log', sys_get_temp_dir() . '/reldyn_consent_test.log');   // the gate logs every decision
$GLOBALS['PLAYER_NAME'] = 'Kaida';
$GLOBALS['fx'] = [
    'conf' => [],            // conf_opts id => value (JSON text)
    'npc' => [],             // lower(npc_name) => raw JSON text of plugin_extended_data.reldyn.consent (or null: no row value)
    'queries' => [],
    'protected' => [],       // names aiagentNsfwIsProtectedNpc says yes to
    'open_mode' => false,
    'orientation_ok' => [],  // name => bool (default true)
    'nsfw' => [],            // name => NsfwNpcData row
    'aff' => [],             // name => affinity
    'rel' => [],             // name => ['type' => ..., 'aff' => ...] (RelationshipManager::getPlayerRelationship)
    'settings' => [],        // _getNsfwSetting overrides
    'relationship_types' => ['enabled' => true, 'eligible_types' => ['romantic', 'crush', 'ex']],
];

class FixtureDb
{
    public function escape($s) { return str_replace("'", "''", (string)$s); }

    public function fetchOne($sql)
    {
        $GLOBALS['fx']['queries'][] = $sql;
        if (preg_match("/FROM conf_opts WHERE id = '([^']+)'/", $sql, $m)) {
            $id = $m[1];
            if ($id === 'aiagent_nsfw_reltypes') {
                return ['value' => json_encode($GLOBALS['fx']['relationship_types'])];
            }
            return isset($GLOBALS['fx']['conf'][$id]) ? ['value' => $GLOBALS['fx']['conf'][$id]] : [];
        }
        if (strpos($sql, "plugin_extended_data -> 'reldyn' -> 'consent'") !== false) {
            if (!preg_match("/lower\\('((?:[^']|'')*)'\\)/", $sql, $m)) { return []; }
            $name = strtolower(str_replace("''", "'", $m[1]));
            if (!array_key_exists($name, $GLOBALS['fx']['npc'])) { return []; }   // no such NPC row
            return ['consent' => $GLOBALS['fx']['npc'][$name]];                   // null = the key is absent
        }
        return [];
    }
}
$GLOBALS['db'] = new FixtureDb();

// Sharmat's own helpers, as far as aiagentNsfwRelTypeSexEligible() and the bridge ask them
function _getNsfwSetting($key, $default = null) { return array_key_exists($key, $GLOBALS['fx']['settings']) ? $GLOBALS['fx']['settings'][$key] : $default; }
function aiagentNsfwArousalNum($key, $default) { return $GLOBALS['fx']['settings'][$key] ?? $default; }
function aiagentNsfwIsProtectedNpc($n) { return in_array($n, $GLOBALS['fx']['protected'], true); }
function aiagentNsfwIsChildNpc($n) { return in_array($n, $GLOBALS['fx']['protected'], true); }
function aiagentNsfwOpenMode() { return $GLOBALS['fx']['open_mode']; }
function aiagentNsfwIsSlutNpc($n) { return false; }
function aiagentNsfwOrientationAllowsPlayer($n) { return $GLOBALS['fx']['orientation_ok'][$n] ?? true; }
function getNpcAffinity($n, $t = null) { return $GLOBALS['fx']['aff'][$n] ?? 0; }
function aiagentNsfwCanonicalRelationshipType($t) { return strtolower(trim((string)$t)); }
class NsfwNpcData
{
    public static function get($n) { return $GLOBALS['fx']['nsfw'][$n] ?? []; }
}
class RelationshipManager
{
    public static function getPlayerRelationship($n) { return $GLOBALS['fx']['rel'][$n] ?? ['type' => 'neutral', 'aff' => 0]; }
}

require_once dirname(__DIR__) . '/reldyn_consent_policy.php';

// The real function, as edited, out of common.php: tokenizer extraction, the function's own nsfw_data require stubbed (the class is above)
function extractFunctionSource($file, $name)
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
$eligibleSource = extractFunctionSource(dirname(__DIR__) . '/common.php', 'aiagentNsfwRelTypeSexEligible');
checkRelDynConsent($eligibleSource !== null, 'aiagentNsfwRelTypeSexEligible must be found in common.php');
checkRelDynConsent(strpos((string)$eligibleSource, 'aiagentNsfwRelDynConsentEligibility') !== false, 'the edited function must carry the RelDyn hook');
$eligibleSource = str_replace('require_once __DIR__ . "/nsfw_data.php";', '// nsfw_data.php: stubbed by the harness', (string)$eligibleSource);
eval($eligibleSource);
checkRelDynConsent(function_exists('aiagentNsfwRelTypeSexEligible'), 'the extracted function must load');
// the rest of the wiring is text: common.php requires the bridge; the auto-accept guard and the prompt mirror carry the hook
checkRelDynConsent(strpos(file_get_contents(dirname(__DIR__) . '/common.php'), 'reldyn_consent_policy.php') !== false, 'common.php must require the bridge');
checkRelDynConsent(strpos(file_get_contents(dirname(__DIR__) . '/prerequest.php'), 'aiagentNsfwRelDynRefuses') !== false, 'the open-mode auto-accept must respect RelDyn\'s no');
checkRelDynConsent(strpos(file_get_contents(dirname(__DIR__) . '/nsfw_relationship.php'), 'aiagentNsfwRelDynConsentDecision') !== false, 'the prompt mirror must follow RelDyn\'s decision');

// ---------------------------------------------------------------- published fixtures (RelDynConsent::payload())

$published = [
    'willing'   => '{"v":1,"enabled":true,"allow":true,"stance":"willing","closed":false,"reasons":[],"appeasing":false,"willingness":1,"bar":0.426,"felt":null,"gamets":2072916666}',
    'hesitant'  => '{"v":1,"enabled":true,"allow":true,"stance":"hesitant","closed":false,"reasons":["pulled_back"],"appeasing":false,"willingness":0.45,"bar":0.39,"felt":"Aela the Huntress is torn: open to this, and unsure. Gentleness and patience from Kaida could tip it either way; rushing would not.","gamets":2072916666}',
    'appeasing' => '{"v":1,"enabled":true,"allow":true,"stance":"appeasing","closed":false,"reasons":["conflict"],"appeasing":true,"willingness":0.3061,"bar":0.39,"felt":"Aela the Huntress does not really want this and is going along with it anyway, to keep Kaida happy and to avoid a fight or being left. There is little of Aela the Huntress in it.","gamets":2072916666}',
    'declines'  => '{"v":1,"enabled":true,"allow":false,"stance":"declines","closed":false,"reasons":["conflict"],"appeasing":false,"willingness":0.1746,"bar":0.444,"felt":"Aela the Huntress and Kaida are in the middle of a quarrel, and wanting Kaida close is the last thing on Aela the Huntress\'s mind. The answer is no, for now.","gamets":2072916666}',
    'closed'    => '{"v":1,"enabled":true,"allow":false,"stance":"closed","closed":true,"reasons":["asexual"],"appeasing":false,"willingness":0,"bar":0.426,"felt":"Sex is not something Aela the Huntress wants, with Kaida or with anyone. That is who Aela the Huntress is, and the closeness Aela the Huntress gives is of another kind.","gamets":2072916666}',
];

function relDynWorld(array $set)
{
    aiagentNsfwRelDynConsentReset();
    $fx = &$GLOBALS['fx'];
    $fx['queries'] = [];
    $fx['conf'] = [];
    $fx['npc'] = [];
    $fx['protected'] = [];
    $fx['open_mode'] = false;
    $fx['orientation_ok'] = [];
    $fx['nsfw'] = [];
    $fx['aff'] = [];
    $fx['rel'] = [];
    $fx['settings'] = [];
    $fx['relationship_types'] = ['enabled' => true, 'eligible_types' => ['romantic', 'crush', 'ex']];
    foreach ($set as $k => $v) { $fx[$k] = $v; }
}
function consentQueries()
{
    return array_filter($GLOBALS['fx']['queries'], function ($q) { return strpos($q, "'consent'") !== false; });
}

$NPC = 'Aela the Huntress';
$romanticGrid = ['rel' => [$NPC => ['type' => 'romantic', 'aff' => 90]], 'aff' => [$NPC => 90]];       // Sharmat's own grid says yes
$friendGrid = ['rel' => [$NPC => ['type' => 'friend', 'aff' => 10]], 'aff' => [$NPC => 10]];            // Sharmat's own grid says no

// ---------------------------------------------------------------- 1. inert without RelDyn

// no RelationshipDynamics class, no ext/relationship_dynamics beside this extension
relDynWorld($romanticGrid + ['npc' => [strtolower($NPC) => $published['declines']]]);
checkRelDynConsent(aiagentNsfwRelDynConsentPresent() === false, 'without RelDyn installed the bridge is not present');
checkRelDynConsent(aiagentNsfwRelDynConsentDecision($NPC) === null, 'without RelDyn there is no decision, whatever is stored');
checkRelDynConsent(aiagentNsfwRelDynRefuses($NPC) === false, 'without RelDyn nothing is refused by the bridge');
checkRelDynConsent(aiagentNsfwRelDynConsentEligibility($NPC) === null, 'without RelDyn the eligibility hook has no opinion');
checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === true, 'without RelDyn a romantic bond at 90 is eligible exactly as before, a stored "declines" notwithstanding');
checkRelDynConsent(consentQueries() === [], 'without RelDyn the consent row is never even read');
relDynWorld($friendGrid + ['npc' => [strtolower($NPC) => $published['willing']]]);
checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === false, 'without RelDyn a friend at 10 is not eligible, a stored "willing" notwithstanding');

// ---------------------------------------------------------------- 2. RelDyn installed
eval('class RelationshipDynamics {}');   // its class is loaded: installed (the real check also accepts its ext directory); eval, so it is not hoisted above section 1

// no decision published: Sharmat's own gate runs
relDynWorld($romanticGrid);
checkRelDynConsent(aiagentNsfwRelDynConsentPresent() === true, 'with RelDyn installed the bridge is present');
checkRelDynConsent(aiagentNsfwRelDynConsentDecision($NPC) === null, 'no NPC row: no decision');
checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === true, 'no decision: the romantic bond is eligible by Sharmat\'s grid');
relDynWorld($friendGrid + ['npc' => [strtolower($NPC) => null]]);
checkRelDynConsent(aiagentNsfwRelDynConsentDecision($NPC) === null, 'a row with no consent key: no decision');
checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === false, 'no decision: the friend is not eligible by Sharmat\'s grid');

// RelDyn says no: Sharmat refuses, even where its own grid, an open mode and a high affinity say yes
foreach (['declines', 'closed'] as $stance) {
    relDynWorld($romanticGrid + ['open_mode' => true, 'npc' => [strtolower($NPC) => $published[$stance]]]);
    $d = aiagentNsfwRelDynConsentDecision($NPC);
    checkRelDynConsent($d !== null && $d['allow'] === false && $d['stance'] === $stance, "{$stance}: parsed as a no");
    checkRelDynConsent(aiagentNsfwRelDynRefuses($NPC) === true, "{$stance}: the open-mode auto-accept guard sees a refusal");
    checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === false, "{$stance}: RelDyn's no supersedes a romantic bond at 90 in open mode");
}
relDynWorld($romanticGrid + ['npc' => [strtolower($NPC) => $published['closed']]]);
$d = aiagentNsfwRelDynConsentDecision($NPC);
checkRelDynConsent($d['closed'] === true && $d['reasons'] === ['asexual'], 'closed carries its reason');
checkRelDynConsent(strpos($d['felt'], $NPC) !== false && !preg_match('/\b(she|he|her|his|him)\b/i', $d['felt']), 'the felt line names the NPC and assumes no pronoun');

// RelDyn says yes: it replaces the rel-type grid and the affinity floors
foreach (['willing', 'hesitant', 'appeasing'] as $stance) {
    relDynWorld($friendGrid + ['npc' => [strtolower($NPC) => $published[$stance]]]);
    $d = aiagentNsfwRelDynConsentDecision($NPC);
    checkRelDynConsent($d !== null && $d['allow'] === true && $d['stance'] === $stance, "{$stance}: parsed as a yes");
    checkRelDynConsent(aiagentNsfwRelDynRefuses($NPC) === false, "{$stance}: not a refusal");
    checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === true, "{$stance}: RelDyn's yes supersedes a friend at 10");
}
relDynWorld($friendGrid + ['npc' => [strtolower($NPC) => $published['appeasing']]]);
$d = aiagentNsfwRelDynConsentDecision($NPC);
checkRelDynConsent($d['appeasing'] === true && strpos($d['felt'], 'going along with it anyway') !== false, 'an NPC who gives in carries the words to play it');

// what RelDyn does not read still only restricts
relDynWorld($friendGrid + ['npc' => [strtolower($NPC) => $published['willing']], 'orientation_ok' => [$NPC => false]]);
checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === false, 'RelDyn yes, but Sharmat\'s own orientation gate still says no');
relDynWorld(array_replace($friendGrid, ['npc' => [strtolower($NPC) => $published['willing']],
    'nsfw' => [$NPC => ['spouse_names' => 'Njada Stonearm', 'spousal_status' => 'married']], 'aff' => [$NPC => 20]]));
checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === false, 'RelDyn yes, married to another and below the affair floor: still no');
relDynWorld(array_replace($friendGrid, ['npc' => [strtolower($NPC) => $published['willing']],
    'nsfw' => [$NPC => ['spouse_names' => 'Njada Stonearm', 'spousal_status' => 'married']], 'aff' => [$NPC => 60]]));
checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === true, 'RelDyn yes, married to another at the affair floor: yes');
relDynWorld(array_replace($friendGrid, ['npc' => [strtolower($NPC) => $published['willing']],
    'nsfw' => [$NPC => ['spouse_names' => 'Kaida', 'spousal_status' => 'married']], 'aff' => [$NPC => 5]]));
checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === true, 'RelDyn yes, married to the player: no affair floor');
// children are walled off before anything else
relDynWorld($friendGrid + ['npc' => [strtolower($NPC) => $published['willing']], 'protected' => [$NPC]]);
checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === false, 'a protected NPC is never eligible, a RelDyn yes notwithstanding');

// not a decision: malformed, switched off, another version, or contradicting itself
$bad = [
    'switched off by RelDyn' => '{"v":1,"enabled":false,"gamets":2072916666}',
    'another version'        => str_replace('"v":1', '"v":2', $published['declines']),
    'allow not a boolean'    => str_replace('"allow":false', '"allow":"no"', $published['declines']),
    'allow against stance'   => str_replace('"allow":false', '"allow":true', $published['declines']),
    'unknown stance'         => str_replace('"declines"', '"maybe"', $published['declines']),
    'not json'               => '{nope',
    'not an object'          => '"declines"',
];
foreach ($bad as $why => $raw) {
    relDynWorld($romanticGrid + ['npc' => [strtolower($NPC) => $raw]]);
    checkRelDynConsent(aiagentNsfwRelDynConsentDecision($NPC) === null, "{$why}: no decision");
    checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === true, "{$why}: Sharmat's own gate runs (romantic at 90: eligible)");
}
checkRelDynConsent(aiagentNsfwRelDynConsentFromRow(json_decode($published['willing'], true))['allow'] === true, 'a decoded row parses too');
checkRelDynConsent(aiagentNsfwRelDynConsentFromRow(null) === null, 'a null row is no decision');

// the switches
relDynWorld($romanticGrid + ['npc' => [strtolower($NPC) => $published['declines']], 'conf' => ['relationship_dynamics_config' => '{"enabled":false}']]);
checkRelDynConsent(aiagentNsfwRelDynConsentPresent() === false, 'RelDyn switched off in its own config: the bridge is not present');
checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === true, 'RelDyn switched off: its stale "declines" is ignored and Sharmat decides');
relDynWorld($romanticGrid + ['npc' => [strtolower($NPC) => $published['declines']], 'conf' => ['relationship_dynamics_config' => '{"enabled":true}']]);
checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === false, 'RelDyn switched on in its config: its "declines" stands');
relDynWorld($romanticGrid + ['npc' => [strtolower($NPC) => $published['declines']], 'settings' => ['NSFW_RELDYN_CONSENT' => false]]);
checkRelDynConsent(aiagentNsfwRelDynConsentPresent() === false, 'NSFW_RELDYN_CONSENT off: the bridge is not present');
checkRelDynConsent(aiagentNsfwRelTypeSexEligible($NPC) === true, 'NSFW_RELDYN_CONSENT off: Sharmat\'s own consent as before');

// reading: one query per NPC per request, names matched without case, quotes escaped
relDynWorld($romanticGrid + ['npc' => [strtolower($NPC) => $published['declines'], "d'arcy" => $published['willing']]]);
aiagentNsfwRelDynConsentDecision($NPC);
aiagentNsfwRelDynConsentDecision(strtoupper($NPC));
aiagentNsfwRelDynRefuses($NPC);
checkRelDynConsent(count(consentQueries()) === 1, 'one consent query per NPC per request: ' . count(consentQueries()));
checkRelDynConsent(aiagentNsfwRelDynConsentDecision("D'Arcy")['stance'] === 'willing', 'a name with a quote is escaped and found, matched without case');
checkRelDynConsent(aiagentNsfwRelDynConsentDecision('') === null && aiagentNsfwRelDynConsentDecision('The Narrator') === null, 'an empty or unknown name has no decision');

if ($failures) {
    fwrite(STDERR, "RelDyn consent hook regression failures:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "RelDyn consent hook regression tests passed ({$assertions} assertions).\n";
