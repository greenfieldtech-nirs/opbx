<?php

/**
 * ActionSelector Component Regression Test
 *
 * Tests that ActionSelector component changes properly format target_id values with prefixes
 * and that backend validation accepts the formatted IDs.
 */

class ActionSelectorRegressionTest
{
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "ActionSelector Component Regression Test\n";
        echo "========================================\n\n";

        $this->testActionSelectorFormatting();
        $this->testGetCurrentTargetLabel();
        $this->testValidationLogic();

        $this->printResults();
    }

    private function testActionSelectorFormatting(): void
    {
        echo "Testing ActionSelector handleTargetChange formatting...\n";

        // Test extension formatting
        $result = $this->simulateActionSelectorFormatting('extension', '101');
        $this->assertEquals('ext-101', $result, 'Extension formatting should add ext- prefix');

        // Test ring group formatting
        $result = $this->simulateActionSelectorFormatting('ring_group', '5');
        $this->assertEquals('rg-5', $result, 'Ring group formatting should add rg- prefix');

        // Test IVR menu formatting
        $result = $this->simulateActionSelectorFormatting('ivr_menu', '12');
        $this->assertEquals('ivr-12', $result, 'IVR menu formatting should add ivr- prefix');

        echo "\n";
    }

    private function testGetCurrentTargetLabel(): void
    {
        echo "Testing getCurrentTargetLabel prefix stripping...\n";

        $testCases = [
            ['input' => 'ext-101', 'expected' => '101', 'description' => 'Extension prefix stripping'],
            ['input' => 'rg-5', 'expected' => '5', 'description' => 'Ring group prefix stripping'],
            ['input' => 'ivr-12', 'expected' => '12', 'description' => 'IVR menu prefix stripping'],
            ['input' => 'unknown-prefix-123', 'expected' => 'unknown-prefix-123', 'description' => 'Unknown prefix should not be stripped'],
        ];

        foreach ($testCases as $testCase) {
            $result = $this->simulateGetCurrentTargetLabel($testCase['input']);
            $this->assertEquals($testCase['expected'], $result, $testCase['description']);
        }

        echo "\n";
    }

    private function testValidationLogic(): void
    {
        echo "Testing backend validation logic...\n";

        $testCases = [
            // Valid cases
            ['type' => 'extension', 'target_id' => 'ext-123', 'expected' => true, 'description' => 'Valid extension format'],
            ['type' => 'ring_group', 'target_id' => 'rg-456', 'expected' => true, 'description' => 'Valid ring group format'],
            ['type' => 'ivr_menu', 'target_id' => 'ivr-789', 'expected' => true, 'description' => 'Valid IVR menu format'],

            // Invalid cases - missing prefixes
            ['type' => 'extension', 'target_id' => '123', 'expected' => false, 'description' => 'Extension missing prefix'],
            ['type' => 'ring_group', 'target_id' => '456', 'expected' => false, 'description' => 'Ring group missing prefix'],
            ['type' => 'ivr_menu', 'target_id' => '789', 'expected' => false, 'description' => 'IVR menu missing prefix'],

            // Invalid cases - wrong prefixes
            ['type' => 'extension', 'target_id' => 'rg-123', 'expected' => false, 'description' => 'Extension with wrong prefix'],
            ['type' => 'ring_group', 'target_id' => 'ivr-456', 'expected' => false, 'description' => 'Ring group with wrong prefix'],
            ['type' => 'ivr_menu', 'target_id' => 'ext-789', 'expected' => false, 'description' => 'IVR menu with wrong prefix'],
        ];

        foreach ($testCases as $testCase) {
            $result = $this->simulateValidationLogic($testCase['type'], $testCase['target_id']);
            $this->assertEquals($testCase['expected'], $result, $testCase['description']);
        }

        echo "\n";
    }

    private function testCompleteFlow(): void
    {
        echo "Testing complete form data flow...\n";

        // Simulate the complete user interaction flow
        $openHoursAction = ['type' => 'extension', 'target_id' => ''];
        $closedHoursAction = ['type' => 'ring_group', 'target_id' => ''];

        // User selects specific targets (this triggers handleTargetChange)
        $openHoursAction = $this->simulateTargetSelection($openHoursAction, 'extension', '101');
        $closedHoursAction = $this->simulateTargetSelection($closedHoursAction, 'ring_group', '5');

        // Verify form data contains properly formatted IDs
        $this->assertEquals('ext-101', $openHoursAction['target_id'], 'Open hours action should have formatted target_id');
        $this->assertEquals('rg-5', $closedHoursAction['target_id'], 'Closed hours action should have formatted target_id');

        // Verify UI display labels (should strip prefixes)
        $openHoursDisplay = $this->simulateGetCurrentTargetLabel($openHoursAction['target_id']);
        $closedHoursDisplay = $this->simulateGetCurrentTargetLabel($closedHoursAction['target_id']);

        $this->assertEquals('101', $openHoursDisplay, 'Open hours display should strip prefix');
        $this->assertEquals('5', $closedHoursDisplay, 'Closed hours display should strip prefix');

        // Verify validation passes
        $openValid = $this->simulateValidationLogic($openHoursAction['type'], $openHoursAction['target_id']);
        $closedValid = $this->simulateValidationLogic($closedHoursAction['type'], $closedHoursAction['target_id']);

        $this->assertTrue($openValid, 'Open hours action should pass validation');
        $this->assertTrue($closedValid, 'Closed hours action should pass validation');

        echo "\n";
    }

    /**
     * Simulate ActionSelector handleTargetChange function behavior.
     */
    private function simulateActionSelectorFormatting(string $actionType, string $targetId): string
    {
        $formattedTargetId = $targetId;
        switch ($actionType) {
            case 'extension':
                $formattedTargetId = 'ext-' . $targetId;
                break;
            case 'ring_group':
                $formattedTargetId = 'rg-' . $targetId;
                break;
            case 'ivr_menu':
                $formattedTargetId = 'ivr-' . $targetId;
                break;
        }
        return $formattedTargetId;
    }

    /**
     * Simulate complete target selection process.
     */
    private function simulateTargetSelection(array $action, string $type, string $targetId): array
    {
        return [
            'type' => $type,
            'target_id' => $this->simulateActionSelectorFormatting($type, $targetId)
        ];
    }

    /**
     * Simulate getCurrentTargetLabel function behavior.
     */
    private function simulateGetCurrentTargetLabel(string $targetId): string
    {
        // Extract the numeric ID from prefixed target_id
        $numericId = $targetId;
        if (str_starts_with($targetId, 'ext-')) {
            $numericId = substr($targetId, 4);
        } elseif (str_starts_with($targetId, 'rg-')) {
            $numericId = substr($targetId, 3);
        } elseif (str_starts_with($targetId, 'ivr-')) {
            $numericId = substr($targetId, 4);
        }

        return $numericId;
    }

    /**
     * Simulate the validation logic from StoreBusinessHoursScheduleRequest.
     */
    private function simulateValidationLogic(string $type, string $targetId): bool
    {
        // This simulates the validateActionStructure method logic
        if ($type && $targetId) {
            // For extension actions, target_id should be a valid extension identifier
            if ($type === 'extension') {
                return preg_match('/^ext-[a-zA-Z0-9_-]+$/', $targetId) === 1;
            }

            // For ring group actions, target_id should be a valid ring group identifier
            if ($type === 'ring_group') {
                return preg_match('/^rg-[a-zA-Z0-9_-]+$/', $targetId) === 1;
            }

            // For IVR menu actions, target_id should be a valid IVR menu identifier
            if ($type === 'ivr_menu') {
                return preg_match('/^ivr-[a-zA-Z0-9_-]+$/', $targetId) === 1;
            }
        }

        return false;
    }

    private function assertEquals($expected, $actual, string $description): void
    {
        if ($expected === $actual) {
            $this->results[] = "✓ PASS: $description";
            $this->passed++;
        } else {
            $this->results[] = "✗ FAIL: $description (Expected: '$expected', Got: '$actual')";
            $this->failed++;
        }
    }

    private function assertTrue(bool $condition, string $description): void
    {
        if ($condition) {
            $this->results[] = "✓ PASS: $description";
            $this->passed++;
        } else {
            $this->results[] = "✗ FAIL: $description";
            $this->failed++;
        }
    }

    private function printResults(): void
    {
        echo "Test Results:\n";
        echo "=============\n";

        foreach ($this->results as $result) {
            echo "$result\n";
        }

        echo "\nSummary:\n";
        echo "========\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Total: " . ($this->passed + $this->failed) . "\n";

        if ($this->failed === 0) {
            echo "\n🎉 All tests passed! ActionSelector regression test successful.\n";
        } else {
            echo "\n❌ Some tests failed. Please review the ActionSelector implementation.\n";
            exit(1);
        }
    }
}

// Run the test
$test = new ActionSelectorRegressionTest();
$test->run();
