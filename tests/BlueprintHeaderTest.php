<?php

declare(strict_types=1);

namespace Grav\Plugin\GuardHelper\Tests;

use Grav\Plugin\GuardHelper\BlueprintHeader;
use PHPUnit\Framework\TestCase;

/**
 * The line reader for a blueprint's name and version stands in for a YAML
 * parse on every dashboard load, so it has to give the parse's answer or say
 * it cannot: a wrong version would hide a vulnerable package from the check.
 */
final class BlueprintHeaderTest extends TestCase
{
    public function testReadsPlainValues(): void
    {
        $src = "name: Guard Helper\nslug: guard-helper\ntype: plugin\nversion: 1.0.4\ndescription: Something\n";
        $this->assertSame(['name' => 'Guard Helper', 'version' => '1.0.4'], BlueprintHeader::read($src));
    }

    public function testReadsQuotedValues(): void
    {
        $this->assertSame(['name' => 'Form', 'version' => '7.1.0'], BlueprintHeader::read("name: 'Form'\nversion: \"7.1.0\"\n"));
        // A doubled quote inside single quotes is one quote.
        $this->assertSame("Andy's Plugin", BlueprintHeader::scalar("name: 'Andy''s Plugin'\n", 'name'));
        // Quoted numbers stay strings, as they do in YAML.
        $this->assertSame('1.0', BlueprintHeader::scalar("version: '1.0'\n", 'version'));
    }

    public function testIgnoresATrailingCommentAndWindowsLineEndings(): void
    {
        $this->assertSame('2.1.0', BlueprintHeader::scalar("version: 2.1.0 # next\n", 'version'));
        $this->assertSame('2.1.0', BlueprintHeader::scalar("version: '2.1.0' # next\n", 'version'));
        $this->assertSame(['name' => 'Admin', 'version' => '1.0.0'], BlueprintHeader::read("name: Admin\r\nversion: 1.0.0\r\n"));
        // A hash inside a word is part of the value, not a comment.
        $this->assertSame('C#sharp', BlueprintHeader::scalar("name: C#sharp\n", 'name'));
    }

    public function testNeverTakesAKeyNestedUnderAnotherOne(): void
    {
        $src = "dependencies:\n    - { name: form, version: '>=4.0' }\n    - name: login\n      version: 2.0.0\nname: Real\nversion: 3.0.0\n";
        $this->assertSame(['name' => 'Real', 'version' => '3.0.0'], BlueprintHeader::read($src));
        // Only nested keys, no top-level one: nothing to read.
        $this->assertNull(BlueprintHeader::scalar("dependencies:\n  - name: login\n    version: 2.0.0\n", 'version'));
    }

    public function testTheFirstTopLevelMatchWins(): void
    {
        $this->assertSame('1.0.0', BlueprintHeader::scalar("version: 1.0.0\nform:\n  fields:\nversion: 9.9.9\n", 'version'));
    }

    public function testAKeyThatOnlyStartsWithTheNameIsNotTheKey(): void
    {
        $this->assertNull(BlueprintHeader::scalar("version_note: 1.0.0\nnamespace: x\n", 'version'));
        $this->assertNull(BlueprintHeader::scalar("namespace: x\n", 'name'));
    }

    /**
     * @dataProvider valuesNeedingYaml
     */
    public function testLeavesAnythingNotClearlyAStringToYaml(string $line): void
    {
        $this->assertNull(BlueprintHeader::scalar($line . "\n", 'version'));
    }

    /** @return array<string,array{string}> */
    public static function valuesNeedingYaml(): array
    {
        return [
            'float' => ['version: 1.0'],
            'integer' => ['version: 2'],
            'exponent' => ['version: 1e3'],
            'signed' => ['version: -1'],
            'boolean' => ['version: true'],
            'yes' => ['version: Yes'],
            'null' => ['version: null'],
            'tilde' => ['version: ~'],
            'empty' => ['version:'],
            'block scalar' => ['version: >'],
            'flow list' => ['version: [1, 2]'],
            'tag' => ['version: !!str 1.0'],
            'anchor' => ['version: &v 1.0.0'],
            'escape in double quotes' => ['version: "1.0\\u002e0"'],
            'unclosed quote' => ['version: "1.0.0'],
            'colon in value' => ['version: a: b'],
        ];
    }

    public function testAValueContinuingOnTheNextLineNeedsYaml(): void
    {
        $this->assertNull(BlueprintHeader::scalar("name: A long\n  name here\nversion: 1.0.0\n", 'name'));
        $this->assertSame('1.0.0', BlueprintHeader::scalar("name: A long\n  name here\nversion: 1.0.0\n", 'version'));
    }

    public function testReadNeedsBothKeys(): void
    {
        $this->assertNull(BlueprintHeader::read("version: 1.0.0\n"));
        $this->assertNull(BlueprintHeader::read("name: Thing\n"));
        $this->assertNull(BlueprintHeader::read("name: Thing\nversion: 1.0\n"));
        $this->assertNull(BlueprintHeader::read(''));
    }
}
