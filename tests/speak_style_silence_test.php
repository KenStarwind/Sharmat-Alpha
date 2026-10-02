<?php

/**
 * Regression harness for the speak style editor's silence_chance field (LOCAL, branch reldyn-scenes): the real
 * handleSaveGlobalStyle() and handleLoadGlobalStyle() out of config_manager.php (extracted with the tokenizer: the file is a
 * whole admin page) run against a fake database, and NsfwData::getSpeakStyle()'s reading of the stored field.
 *
 * Run: php tests/speak_style_silence_test.php
 */

$failures = [];
$assertions = 0;
function checkStyleSilence($condition, $message)
{
    global $failures, $assertions;
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function extractStyleFn($file, $name)
{
    $tokens = token_get_all(file_get_contents($file));
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

class FakeStyleDb
{
    public $value = '{"reserved":{"name":"reserved","description":"d","content":"c","emoji":"x"}}';
    public function escape($s) { return $s; }
    public function fetchOne($sql)
    {
        if (strpos($sql, 'SELECT value') !== false) { return ['value' => $this->value]; }
        if (preg_match("/\\(value::jsonb\\)->'([^']+)' as style_data/", $sql, $m)) {
            $all = json_decode($this->value, true) ?: [];
            return ['style_data' => isset($all[$m[1]]) ? json_encode($all[$m[1]]) : null];
        }
        return ['id' => 'aiagent_nsfw_speak_styles'];
    }
    public function execQuery($sql)
    {
        if (preg_match("/SET value = '(.*)' WHERE/s", $sql, $m)) { $this->value = $m[1]; }
        return true;
    }
}
$GLOBALS['db'] = new FakeStyleDb();

$manager = dirname(__DIR__) . '/config_manager.php';
foreach (['handleSaveGlobalStyle', 'handleLoadGlobalStyle'] as $fn) {
    $src = extractStyleFn($manager, $fn);
    checkStyleSilence($src !== null, "{$fn} must be found in config_manager.php");
    eval(str_replace('exit;', 'return;', (string)$src));   // the handlers end the request; the harness wants the output
}

// NsfwData::getSpeakStyle(), the real method, on the same fake database
$dataSrc = file_get_contents(dirname(__DIR__) . '/nsfw_data.php');
$method = extractStyleFn(dirname(__DIR__) . '/nsfw_data.php', 'getSpeakStyle');
checkStyleSilence($method !== null, 'getSpeakStyle must be found in nsfw_data.php');
eval('class StyleReader { public ' . str_replace('function getSpeakStyle', 'static function getSpeakStyle', (string)$method) . ' }');

$cases = [
    // percent in the form => the stored fraction (null = no field stored)
    ['70', 0.7], ['', null], ['150', 0.9], ['-5', null], ['abc', null], ['35.5', 0.355], ['0', null], ['5', 0.05],
];
foreach ($cases as $i => [$pct, $expected]) {
    $name = 'style' . $i;
    $_POST = ['name' => $name, 'content' => 'cue', 'emoji' => 'e', 'description' => 'desc', 'silence_chance' => $pct];
    ob_start(); handleSaveGlobalStyle(); $saved = json_decode((string)ob_get_clean(), true);
    checkStyleSilence(!empty($saved['success']), "saving with '{$pct}' succeeds");
    $stored = json_decode($GLOBALS['db']->value, true)[$name] ?? [];
    $storedVal = array_key_exists('silence_chance', $stored) ? $stored['silence_chance'] : null;
    checkStyleSilence($storedVal === $expected, "'{$pct}' is stored as " . var_export($expected, true) . ', got ' . var_export($storedVal, true));
    $_GET = ['style' => $name];
    ob_start(); handleLoadGlobalStyle(); $loaded = json_decode((string)ob_get_clean(), true);
    checkStyleSilence(array_key_exists('silence_chance', $loaded) && $loaded['silence_chance'] === $expected, "'{$pct}' is loaded back as " . var_export($expected, true));
    $read = StyleReader::getSpeakStyle($name);
    checkStyleSilence(is_array($read) && array_key_exists('silence_chance', $read) && $read['silence_chance'] === $expected, "getSpeakStyle reads '{$pct}' as " . var_export($expected, true));
    checkStyleSilence(($read['content'] ?? '') === 'cue', 'and the rest of the style is intact');
}
// a style saved before the field existed reads as none
$old = StyleReader::getSpeakStyle('reserved');
checkStyleSilence(is_array($old) && $old['silence_chance'] === null, 'a style saved before the field existed has no silence chance');
// the ceiling of the form is 90 percent: no style can be set to certain silence
$_POST = ['name' => 'ceiling', 'content' => 'c', 'silence_chance' => '100'];
ob_start(); handleSaveGlobalStyle(); ob_end_clean();
checkStyleSilence((json_decode($GLOBALS['db']->value, true)['ceiling']['silence_chance'] ?? 0) <= 0.9, 'the form holds a style under certain silence');

if ($failures) {
    fwrite(STDERR, "Speak style silence field tests FAILED (" . count($failures) . " of {$assertions}):\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "Speak style silence field tests passed ({$assertions} assertions).\n";
