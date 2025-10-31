<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use RuntimeException;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\orm\validators\EmailValidator;
use tommyknocker\pdodb\orm\validators\IntegerValidator;
use tommyknocker\pdodb\orm\validators\RequiredValidator;
use tommyknocker\pdodb\orm\validators\StringValidator;
use tommyknocker\pdodb\orm\validators\ValidatorFactory;
use tommyknocker\pdodb\orm\validators\ValidatorInterface;

/**
 * Test model for validation tests.
 */
class ValidatorTestModel extends Model
{
    public static function tableName(): string
    {
        return 'test_validation';
    }
}

/**
 * Test model with validation rules.
 */
final class ValidatorTestModelWithRules extends ValidatorTestModel
{
    public static function rules(): array
    {
        return [
            [['name', 'email'], 'required'],
            ['email', 'email'],
            ['age', 'integer', 'min' => 1, 'max' => 150],
        ];
    }
}

/**
 * Test model with single validation rule.
 */
final class ValidatorTestModelSingleRule extends ValidatorTestModel
{
    public static function rules(): array
    {
        return [
            ['name', 'required'],
            ['email', 'email'],
        ];
    }
}

/**
 * Test model with custom message.
 */
final class ValidatorTestModelCustomMessage extends ValidatorTestModel
{
    public static function rules(): array
    {
        return [
            ['name', 'required', 'message' => 'Name is mandatory'],
        ];
    }
}

/**
 * Test model with multiple rules for same attribute.
 */
final class ValidatorTestModelMultipleRules extends ValidatorTestModel
{
    public static function rules(): array
    {
        return [
            ['name', 'required'],
            ['name', 'string', 'min' => 3],
        ];
    }
}

/**
 * Test model with unknown validator.
 */
final class ValidatorTestModelUnknownValidator extends ValidatorTestModel
{
    public static function rules(): array
    {
        return [
            ['name', 'required'],
            ['name', 'unknown-validator'],
        ];
    }
}

/**
 * Test model for save validation.
 */
final class ValidatorTestModelSave extends ValidatorTestModel
{
    public static function rules(): array
    {
        return [
            [['name', 'email'], 'required'],
        ];
    }
}

/**
 * ValidatorTests for validator functionality.
 */
final class ValidatorTests extends BaseSharedTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create test_validation table if it doesn't exist
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_validation (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                email TEXT,
                age INTEGER
            )
        ');

        ValidatorTestModel::setDb(self::$db);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Clear test_validation table
        self::$db->rawQuery('DELETE FROM test_validation');
        self::$db->rawQuery("DELETE FROM sqlite_sequence WHERE name='test_validation'");
    }
    public function testRequiredValidator(): void
    {
        $validator = new RequiredValidator();
        $model = new ValidatorTestModel();

        // Valid: non-empty string
        $this->assertTrue($validator->validate($model, 'name', 'John'));

        // Valid: non-empty integer
        $this->assertTrue($validator->validate($model, 'age', 30));

        // Valid: zero (not empty)
        $this->assertTrue($validator->validate($model, 'count', 0));

        // Valid: string '0' (not empty)
        $this->assertTrue($validator->validate($model, 'value', '0'));

        // Invalid: null
        $this->assertFalse($validator->validate($model, 'name', null));

        // Invalid: empty string
        $this->assertFalse($validator->validate($model, 'name', ''));

        // Invalid: whitespace-only string
        $this->assertFalse($validator->validate($model, 'name', '   '));
    }

    public function testRequiredValidatorMessage(): void
    {
        $validator = new RequiredValidator();
        $model = new ValidatorTestModel();

        $message = $validator->getMessage($model, 'name');
        $this->assertStringContainsString('required', strtolower($message));

        // Custom message
        $customMessage = $validator->getMessage($model, 'name', ['message' => 'Name is required']);
        $this->assertEquals('Name is required', $customMessage);
    }

    public function testEmailValidator(): void
    {
        $validator = new EmailValidator();
        $model = new ValidatorTestModel();

        // Valid: proper email
        $this->assertTrue($validator->validate($model, 'email', 'user@example.com'));
        $this->assertTrue($validator->validate($model, 'email', 'test.user@example.co.uk'));

        // Valid: empty (handled by RequiredValidator)
        $this->assertTrue($validator->validate($model, 'email', null));
        $this->assertTrue($validator->validate($model, 'email', ''));

        // Invalid: not an email
        $this->assertFalse($validator->validate($model, 'email', 'not-an-email'));
        $this->assertFalse($validator->validate($model, 'email', 'user@'));
        $this->assertFalse($validator->validate($model, 'email', '@example.com'));
        $this->assertFalse($validator->validate($model, 'email', 'user @example.com'));

        // Invalid: not a string
        $this->assertFalse($validator->validate($model, 'email', 123));
    }

    public function testEmailValidatorMessage(): void
    {
        $validator = new EmailValidator();
        $model = new ValidatorTestModel();

        $message = $validator->getMessage($model, 'email');
        $this->assertStringContainsString('email', strtolower($message));
    }

    public function testIntegerValidator(): void
    {
        $validator = new IntegerValidator();
        $model = new ValidatorTestModel();

        // Valid: integer
        $this->assertTrue($validator->validate($model, 'age', 30));
        $this->assertTrue($validator->validate($model, 'age', 0));
        $this->assertTrue($validator->validate($model, 'age', -10));
        $this->assertTrue($validator->validate($model, 'age', '30')); // String representation
        $this->assertTrue($validator->validate($model, 'age', '0'));

        // Valid: empty (handled by RequiredValidator)
        $this->assertTrue($validator->validate($model, 'age', null));
        $this->assertTrue($validator->validate($model, 'age', ''));

        // Invalid: float
        $this->assertFalse($validator->validate($model, 'age', 30.5));
        $this->assertFalse($validator->validate($model, 'age', '30.5'));

        // Invalid: not numeric
        $this->assertFalse($validator->validate($model, 'age', 'abc'));

        // With min constraint
        $this->assertTrue($validator->validate($model, 'age', 30, ['min' => 1]));
        $this->assertTrue($validator->validate($model, 'age', 1, ['min' => 1]));
        $this->assertFalse($validator->validate($model, 'age', 0, ['min' => 1]));

        // With max constraint
        $this->assertTrue($validator->validate($model, 'age', 30, ['max' => 100]));
        $this->assertTrue($validator->validate($model, 'age', 100, ['max' => 100]));
        $this->assertFalse($validator->validate($model, 'age', 101, ['max' => 100]));

        // With both constraints
        $this->assertTrue($validator->validate($model, 'age', 30, ['min' => 1, 'max' => 100]));
        $this->assertFalse($validator->validate($model, 'age', 0, ['min' => 1, 'max' => 100]));
        $this->assertFalse($validator->validate($model, 'age', 101, ['min' => 1, 'max' => 100]));
    }

    public function testIntegerValidatorMessage(): void
    {
        $validator = new IntegerValidator();
        $model = new ValidatorTestModel();

        $message = $validator->getMessage($model, 'age');
        $this->assertStringContainsString('integer', strtolower($message));

        $messageWithMin = $validator->getMessage($model, 'age', ['min' => 1]);
        $this->assertStringContainsString('>=', $messageWithMin);

        $messageWithMax = $validator->getMessage($model, 'age', ['max' => 100]);
        $this->assertStringContainsString('<=', $messageWithMax);

        $messageWithRange = $validator->getMessage($model, 'age', ['min' => 1, 'max' => 100]);
        $this->assertStringContainsString('between', strtolower($messageWithRange));
    }

    public function testStringValidator(): void
    {
        $validator = new StringValidator();
        $model = new ValidatorTestModel();

        // Valid: string
        $this->assertTrue($validator->validate($model, 'name', 'John'));
        $this->assertTrue($validator->validate($model, 'name', ''));
        $this->assertTrue($validator->validate($model, 'name', 123)); // Numeric is converted to string

        // Valid: empty (handled by RequiredValidator)
        $this->assertTrue($validator->validate($model, 'name', null));

        // Invalid: array
        $this->assertFalse($validator->validate($model, 'name', ['test']));

        // With exact length
        $this->assertTrue($validator->validate($model, 'code', '12345', ['length' => 5]));
        $this->assertFalse($validator->validate($model, 'code', '1234', ['length' => 5]));
        $this->assertFalse($validator->validate($model, 'code', '123456', ['length' => 5]));

        // With min length
        $this->assertTrue($validator->validate($model, 'name', 'John', ['min' => 3]));
        $this->assertTrue($validator->validate($model, 'name', 'Jon', ['min' => 3]));
        $this->assertFalse($validator->validate($model, 'name', 'Jo', ['min' => 3]));

        // With max length
        $this->assertTrue($validator->validate($model, 'name', 'John', ['max' => 10]));
        $this->assertTrue($validator->validate($model, 'name', 'John Doe', ['max' => 10]));
        $this->assertFalse($validator->validate($model, 'name', 'John Doe Smith', ['max' => 10]));

        // With both constraints
        $this->assertTrue($validator->validate($model, 'name', 'John', ['min' => 3, 'max' => 10]));
        $this->assertFalse($validator->validate($model, 'name', 'Jo', ['min' => 3, 'max' => 10]));
        $this->assertFalse($validator->validate($model, 'name', 'John Doe Smith', ['min' => 3, 'max' => 10]));
    }

    public function testStringValidatorMessage(): void
    {
        $validator = new StringValidator();
        $model = new ValidatorTestModel();

        $message = $validator->getMessage($model, 'name');
        $this->assertStringContainsString('string', strtolower($message));

        $messageWithLength = $validator->getMessage($model, 'code', ['length' => 5]);
        $this->assertStringContainsString('exactly', strtolower($messageWithLength));

        $messageWithMin = $validator->getMessage($model, 'name', ['min' => 3]);
        $this->assertStringContainsString('at least', strtolower($messageWithMin));

        $messageWithMax = $validator->getMessage($model, 'name', ['max' => 10]);
        $this->assertStringContainsString('at most', strtolower($messageWithMax));

        $messageWithRange = $validator->getMessage($model, 'name', ['min' => 3, 'max' => 10]);
        $this->assertStringContainsString('between', strtolower($messageWithRange));
    }

    public function testValidatorFactory(): void
    {
        // Create by name
        $required = ValidatorFactory::create('required');
        $this->assertInstanceOf(RequiredValidator::class, $required);
        $this->assertInstanceOf(ValidatorInterface::class, $required);

        $email = ValidatorFactory::create('email');
        $this->assertInstanceOf(EmailValidator::class, $email);

        $integer = ValidatorFactory::create('integer');
        $this->assertInstanceOf(IntegerValidator::class, $integer);

        $string = ValidatorFactory::create('string');
        $this->assertInstanceOf(StringValidator::class, $string);

        // Create by instance
        $customValidator = new RequiredValidator();
        $result = ValidatorFactory::create($customValidator);
        $this->assertSame($customValidator, $result);
    }

    public function testValidatorFactoryThrowsExceptionForUnknownValidator(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Validator 'unknown' not found");

        ValidatorFactory::create('unknown');
    }

    public function testValidatorFactoryRegister(): void
    {
        // Register custom validator
        $customValidator = new class () extends RequiredValidator {
        };

        ValidatorFactory::register('custom-required', $customValidator::class);

        $this->assertTrue(ValidatorFactory::isRegistered('custom-required'));

        $validator = ValidatorFactory::create('custom-required');
        $this->assertInstanceOf($customValidator::class, $validator);
    }

    public function testValidatorFactoryRegisterThrowsExceptionForInvalidClass(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must implement ValidatorInterface');

        ValidatorFactory::register('invalid', \stdClass::class);
    }

    public function testModelValidationWithRules(): void
    {
        $model = new ValidatorTestModelWithRules();

        // Invalid: missing required fields
        $this->assertFalse($model->validate());
        $this->assertTrue($model->hasValidationErrors());
        $errors = $model->getValidationErrors();
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);

        // Invalid: invalid email
        $model->name = 'John';
        $model->email = 'invalid-email';
        $this->assertFalse($model->validate());
        $errors = $model->getValidationErrors();
        $this->assertArrayHasKey('email', $errors);

        // Invalid: age out of range
        $model->email = 'john@example.com';
        $model->age = 200;
        $this->assertFalse($model->validate());
        $errors = $model->getValidationErrors();
        $this->assertArrayHasKey('age', $errors);

        // Valid
        $model->age = 30;
        $this->assertTrue($model->validate());
        $this->assertFalse($model->hasValidationErrors());
    }

    public function testModelValidationErrorsCollection(): void
    {
        $model = new ValidatorTestModelSingleRule();
        $model->email = 'invalid-email';

        $this->assertFalse($model->validate());

        // Check all errors
        $errors = $model->getValidationErrors();
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);

        // Check specific attribute
        $nameErrors = $model->getValidationErrorsForAttribute('name');
        $this->assertNotEmpty($nameErrors);
        $this->assertStringContainsString('required', strtolower($nameErrors[0]));

        $emailErrors = $model->getValidationErrorsForAttribute('email');
        $this->assertNotEmpty($emailErrors);
        $this->assertStringContainsString('email', strtolower($emailErrors[0]));

        // Check non-existent attribute
        $nonExistent = $model->getValidationErrorsForAttribute('non-existent');
        $this->assertEmpty($nonExistent);
    }

    public function testModelValidationClearErrors(): void
    {
        $model = new ValidatorTestModelSingleRule();
        $model->validate(); // Will fail

        $this->assertTrue($model->hasValidationErrors());

        $model->clearValidationErrors();
        $this->assertFalse($model->hasValidationErrors());
        $this->assertEmpty($model->getValidationErrors());
    }

    public function testModelValidationWithMultipleRulesForSameAttribute(): void
    {
        $model = new ValidatorTestModelMultipleRules();

        // Invalid: missing
        $this->assertFalse($model->validate());
        $errors = $model->getValidationErrorsForAttribute('name');
        $this->assertCount(1, $errors); // Only required error

        // Invalid: too short
        $model->name = 'Jo';
        $this->assertFalse($model->validate());
        $errors = $model->getValidationErrorsForAttribute('name');
        $this->assertCount(1, $errors); // Only string length error

        // Valid
        $model->name = 'John';
        $this->assertTrue($model->validate());
    }

    public function testModelValidationWithUnknownValidator(): void
    {
        $model = new ValidatorTestModelUnknownValidator();
        // Should not throw exception, just skip unknown validator
        $this->assertFalse($model->validate());
        // Should still have required error
        $errors = $model->getValidationErrorsForAttribute('name');
        $this->assertNotEmpty($errors);
    }

    public function testModelValidationWithCustomMessage(): void
    {
        $model = new ValidatorTestModelCustomMessage();
        $model->validate();

        $errors = $model->getValidationErrorsForAttribute('name');
        $this->assertNotEmpty($errors);
        $this->assertEquals('Name is mandatory', $errors[0]);
    }

    public function testModelValidationOnSave(): void
    {
        ValidatorTestModel::setDb(self::$db);

        $model = new ValidatorTestModelSave();

        // Save should fail due to validation
        $this->assertFalse($model->save());
        $this->assertTrue($model->hasValidationErrors());

        // Fix errors and save
        $model->name = 'John';
        $model->email = 'john@example.com';
        $this->assertTrue($model->validate());
        $this->assertTrue($model->save());
    }

    public function testModelValidationBypassOnSave(): void
    {
        ValidatorTestModel::setDb(self::$db);

        // Test that validation can be bypassed - use valid data but bypass validation check
        $model = new ValidatorTestModelSingleRule();
        $model->name = 'Test';
        $model->email = 'test@example.com';

        // Save with validation bypassed - should work
        $this->assertTrue($model->save(false)); // Bypass validation

        // Verify validation errors are cleared after successful save
        $this->assertFalse($model->hasValidationErrors());
    }
}
