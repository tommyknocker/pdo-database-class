<!-- omit in toc -->
# Contributing to PdoDb

First off, thanks for taking the time to contribute! ❤️

All types of contributions are encouraged and valued. See the [Table of Contents](#table-of-contents) for different ways to help and details about how this project handles them. Please make sure to read the relevant section before making your contribution. It will make it a lot easier for us maintainers and smooth out the experience for all involved. The community looks forward to your contributions. 🎉

> And if you like the project, but just don't have time to contribute, that's fine. There are other easy ways to support the project and show your appreciation, which we would also be very happy about:
> - Star the project
> - Tweet about it
> - Refer this project in your project's readme
> - Mention the project at local meetups and tell your friends/colleagues

<!-- omit in toc -->
## Table of Contents

- [I Have a Question](#i-have-a-question)
  - [I Want To Contribute](#i-want-to-contribute)
  - [Reporting Bugs](#reporting-bugs)
  - [Suggesting Enhancements](#suggesting-enhancements)
  - [Your First Code Contribution](#your-first-code-contribution)
  - [Improving The Documentation](#improving-the-documentation)
- [Styleguides](#styleguides)
  - [Commit Messages](#commit-messages)
- [Join The Project Team](#join-the-project-team)



## I Have a Question

> If you want to ask a question, we assume that you have read the available [Documentation](https://github.com/tommyknocker/pdo-database-class/README.md) and checked the [Examples](https://github.com/tommyknocker/pdo-database-class/tree/master/examples).

Before you ask a question, it is best to search for existing [Issues](https://github.com/tommyknocker/pdo-database-class/issues) that might help you. In case you have found a suitable issue and still need clarification, you can write your question in this issue. It is also advisable to search the internet for answers first.

### Common Questions

**Q: Which database should I use for development/testing?**
A: SQLite is recommended for development as it requires no setup. All examples work with SQLite by default.

**Q: How do I handle different SQL dialects?**
A: PdoDb automatically handles dialect differences. Use helper functions like `Db::concat()` instead of raw SQL when possible.

**Q: Can I use this in production?**
A: Yes! PdoDb is production-ready with comprehensive error handling, connection pooling, and extensive testing.

**Q: How do I contribute examples?**
A: Add new examples to the `examples/` directory and ensure they work with `composer pdodb:test-examples`.

If you then still feel the need to ask a question and need clarification, we recommend the following:

- Open an [Issue](https://github.com/tommyknocker/pdo-database-class/issues/new) with the "question" label
- Provide as much context as you can about what you're running into
- Include:
  - PHP version
  - Database type and version (MySQL/PostgreSQL/SQLite)
  - PdoDb version
  - Code example that demonstrates your question
  - Expected vs actual behavior

We will then take care of the issue as soon as possible.

<!--
You might want to create a separate issue tag for questions and include it in this description. People should then tag their issues accordingly.

Depending on how large the project is, you may want to outsource the questioning, e.g. to Stack Overflow or Gitter. You may add additional contact and information possibilities:
- IRC
- Slack
- Gitter
- Stack Overflow tag
- Blog
- FAQ
- Roadmap
- E-Mail List
- Forum
-->

## I Want To Contribute

> ### Legal Notice <!-- omit in toc -->
> When contributing to this project, you must agree that you have authored 100% of the content, that you have the necessary rights to the content and that the content you contribute may be provided under the project licence.

### Reporting Bugs

<!-- omit in toc -->
#### Before Submitting a Bug Report

A good bug report shouldn't leave others needing to chase you up for more information. Therefore, we ask you to investigate carefully, collect information and describe the issue in detail in your report. Please complete the following steps in advance to help us fix any potential bug as fast as possible.

- Make sure that you are using the latest version.
- Determine if your bug is really a bug and not an error on your side e.g. using incompatible environment components/versions (Make sure that you have read the [documentation](https://github.com/tommyknocker/pdo-database-class/README.md). If you are looking for support, you might want to check [this section](#i-have-a-question)).
- To see if other users have experienced (and potentially already solved) the same issue you are having, check if there is not already a bug report existing for your bug or error in the [bug tracker](https://github.com/tommyknocker/pdo-database-class/issues?q=label%3Abug).
- Also make sure to search the internet (including Stack Overflow) to see if users outside of the GitHub community have discussed the issue.
- Collect information about the bug:
  - **PHP version** (e.g., PHP 8.4.13)
  - **Database type and version** (MySQL 8.0, PostgreSQL 15, SQLite 3.38, etc.)
  - **PdoDb version** (check `composer show tommyknocker/pdo-database-class`)
  - **OS and platform** (Windows, Linux, macOS, x86, ARM)
  - **Stack trace** - Full error output
  - **SQL query** - The actual SQL being generated (use `toSql()` method)
  - **Minimal reproduction code** - Smallest code that reproduces the issue
  - **Expected vs actual behavior** - What should happen vs what actually happens
  - **Can you reproduce it reliably?** - Does it happen every time?
  - **Does it happen with older PdoDb versions?** - Test with previous versions

<!-- omit in toc -->
#### How Do I Submit a Good Bug Report?

> **Security Issues**: You must never report security related issues, vulnerabilities or bugs including sensitive information to the issue tracker, or elsewhere in public. Instead, security issues must be sent by email to <vasiliy@krivoplyas.com> with the subject "SECURITY: PdoDb vulnerability report".
<!-- You may add a PGP key to allow the messages to be sent encrypted as well. -->

We use GitHub issues to track bugs and errors. If you run into an issue with the project:

- Open an [Issue](https://github.com/tommyknocker/pdo-database-class/issues/new). (Since we can't be sure at this point whether it is a bug or not, we ask you not to talk about a bug yet and not to label the issue.)
- Explain the behavior you would expect and the actual behavior.
- Please provide as much context as possible and describe the *reproduction steps* that someone else can follow to recreate the issue on their own. This usually includes your code. For good bug reports you should isolate the problem and create a reduced test case.
- Provide the information you collected in the previous section.

Once it's filed:

- The project team will label the issue accordingly.
- A team member will try to reproduce the issue with your provided steps. If there are no reproduction steps or no obvious way to reproduce the issue, the team will ask you for those steps and mark the issue as `needs-repro`. Bugs with the `needs-repro` tag will not be addressed until they are reproduced.
- If the team is able to reproduce the issue, it will be marked `needs-fix`, as well as possibly other tags (such as `critical`), and the issue will be left to be [implemented by someone](#your-first-code-contribution).

<!-- You might want to create an issue template for bugs and errors that can be used as a guide and that defines the structure of the information to be included. If you do so, reference it here in the description. -->


### Suggesting Enhancements

This section guides you through submitting an enhancement suggestion for PdoDb, **including completely new features and minor improvements to existing functionality**. Following these guidelines will help maintainers and the community to understand your suggestion and find related suggestions.

<!-- omit in toc -->
#### Before Submitting an Enhancement

- Make sure that you are using the latest version of PdoDb
- Read the [documentation](https://github.com/tommyknocker/pdo-database-class/README.md) and check the [examples](https://github.com/tommyknocker/pdo-database-class/tree/master/examples) to see if the functionality already exists
- Check if there's already a helper function in `Db::` that provides similar functionality
- Perform a [search](https://github.com/tommyknocker/pdo-database-class/issues) to see if the enhancement has already been suggested
- Consider if the feature should work across all supported databases (MySQL, MariaDB, PostgreSQL, SQLite)
- Evaluate if the enhancement fits with PdoDb's philosophy of being lightweight and framework-agnostic
- Think about whether this would be useful to the majority of users or just a small subset

<!-- omit in toc -->
#### How Do I Submit a Good Enhancement Suggestion?

Enhancement suggestions are tracked as [GitHub issues](https://github.com/tommyknocker/pdo-database-class/issues).

- Use a **clear and descriptive title** for the issue to identify the suggestion
- Provide a **step-by-step description** of the suggested enhancement with code examples
- **Describe the current behavior** and **explain which behavior you expected to see instead**
- **Include code examples** showing:
  - Current way of achieving the goal (if possible)
  - Proposed new API or functionality
  - Expected SQL output (if applicable)
- **Explain the use case** - When would this feature be useful?
- **Consider cross-database compatibility** - Should this work on MySQL, MariaDB, PostgreSQL, and SQLite?
- **Provide implementation ideas** (optional) - If you have thoughts on how this could be implemented
- **Explain why this enhancement would be useful** to most PdoDb users
- **Reference similar features** in other database libraries if applicable

<!-- You might want to create an issue template for enhancement suggestions that can be used as a guide and that defines the structure of the information to be included. If you do so, reference it here in the description. -->

### Your First Code Contribution

#### Prerequisites

- **PHP 8.4+** - The project requires PHP 8.4 or higher
- **Composer** - For dependency management
- **Git** - For version control
- **Database access** (optional) - MySQL, MariaDB, PostgreSQL, or SQLite for testing

#### Development Setup

1. **Fork and clone the repository:**
   ```bash
   git clone https://github.com/YOUR_USERNAME/pdo-database-class.git
   cd pdo-database-class
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Run tests to ensure everything works:**
   ```bash
   composer pdodb:test
   ```

4. **Run static analysis:**
   ```bash
   composer pdodb:phpstan
   ```

5. **Check code style:**
   ```bash
   composer pdodb:cs-check
   ```

#### Development Workflow

1. **Create a feature branch:**
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make your changes** following the coding standards below

3. **Run quality checks:**
   ```bash
   composer pdodb:check-all  # Runs PHPStan + PHPUnit + Examples
   ```

4. **Commit your changes** with a descriptive message

5. **Push and create a Pull Request**

#### Testing Guidelines

- **Write tests for new features** - All new functionality must have corresponding tests
- **Test across dialects** - Ensure compatibility with MySQL, MariaDB, PostgreSQL, and SQLite
- **Use SharedCoverageTest** for dialect-independent functionality
- **Use dialect-specific tests** (PdoDbMySQLTest, PdoDbPostgreSQLTest, PdoDbSqliteTest) for dialect-specific features
- **Test edge cases** - Include tests for error conditions and boundary cases
- **Test examples** - Run `composer pdodb:test-examples` to ensure all examples work
- **Test performance** - For performance-critical features, include benchmarks

#### Test Structure

**SharedCoverageTest.php** - For functionality that works the same across all databases:
- Helper functions (`Db::concat()`, `Db::count()`, etc.)
- Basic CRUD operations
- Query builder methods
- Exception handling

**Dialect-specific tests** - For database-specific functionality:
- SQL generation differences
- Database-specific features (UPSERT, JSON functions, etc.)
- Error handling differences
- Performance characteristics

**Example tests** - Ensure all examples work:
- Run `composer pdodb:test-examples` before submitting PRs
- Add new examples for new features
- Update existing examples if API changes

#### Code Structure

- **`src/`** - Main source code
  - `PdoDb.php` - Main database class
  - `connection/` - Connection management
  - `dialects/` - Database-specific SQL generation
  - `helpers/` - Helper functions and value objects
  - `query/` - Query builder
  - `exceptions/` - Exception hierarchy
- **`tests/`** - Test files
- **`examples/`** - Usage examples
- **`scripts/`** - Utility scripts

### Improving The Documentation

#### README.md Updates

- **Keep examples current** - Ensure all code examples work with the latest version
- **Update API reference** - Add new methods to the appropriate sections
- **Improve clarity** - Make complex concepts easier to understand
- **Add cross-references** - Link related sections and examples

#### Example Updates

- **Test all examples** - Run `composer pdodb:test-examples` to ensure they work
- **Update for new features** - Add examples for new functionality
- **Improve comments** - Make examples self-documenting
- **Add real-world scenarios** - Show practical usage patterns

#### CHANGELOG.md

- **Follow Keep a Changelog format** - Use the established structure
- **Be descriptive** - Explain what changed and why
- **Include migration notes** - For breaking changes
- **Add technical details** - Test counts, compatibility notes

## Styleguides

### Code Style

We use **PHP CS Fixer** with PSR-12 rules. Run `composer pdodb:cs-fix` to automatically format your code.

**Key style requirements:**
- **PSR-12 compliance** - Follow PHP-FIG standards
- **Strict types** - Always use `declare(strict_types=1);`
- **Type hints** - Use proper type declarations for all parameters and return values
- **PHPDoc** - Document all public methods with proper `@param`, `@return`, and `@throws` tags
- **Array syntax** - Use short array syntax `[]` instead of `array()`
- **No unused imports** - Remove unused `use` statements

### Commit Messages

Follow the [Conventional Commits](https://www.conventionalcommits.org/) specification:

**Format:** `<type>[optional scope]: <description>`

**Types:**
- `feat:` - New feature
- `fix:` - Bug fix
- `docs:` - Documentation changes
- `style:` - Code style changes (formatting, etc.)
- `refactor:` - Code refactoring
- `test:` - Adding or updating tests
- `chore:` - Maintenance tasks

**Examples:**
```
feat: add automatic external reference detection in subqueries
fix: resolve PostgreSQL ambiguous column errors in UPSERT
docs: update README with new batch processing examples
test: add comprehensive tests for external reference detection
chore: update dependencies and improve CI configuration
```

**Best practices:**
- Use present tense ("add feature" not "added feature")
- Keep the first line under 50 characters
- Use the body to explain what and why, not how
- Reference issues and pull requests when relevant

### Pull Request Guidelines

1. **Keep PRs focused** - One feature or fix per PR
2. **Write descriptive titles** - Clear, concise description
3. **Include tests** - All new code must have tests
4. **Update documentation** - Update README, examples, or CHANGELOG as needed
5. **Run quality checks** - Ensure `composer pdodb:check-all` passes
6. **Write clear descriptions** - Explain what changed and why

**PR Template:**
```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Testing
- [ ] Tests pass locally
- [ ] New tests added for new functionality
- [ ] Examples tested on all dialects

## Checklist
- [ ] Code follows project style guidelines
- [ ] Self-review completed
- [ ] Documentation updated
- [ ] CHANGELOG.md updated (if applicable)
```

## Join The Project Team

### Becoming a Maintainer

We're always looking for dedicated contributors who can help maintain the project. To become a maintainer:

1. **Contribute regularly** - Submit quality PRs and help with issues
2. **Show expertise** - Demonstrate deep understanding of the codebase
3. **Help the community** - Answer questions and review PRs
4. **Express interest** - Let us know you'd like to become a maintainer

### Maintainer Responsibilities

- **Review pull requests** - Ensure code quality and project standards
- **Respond to issues** - Help users and triage bug reports
- **Release management** - Prepare releases and maintain versioning
- **Community support** - Answer questions and provide guidance

### Recognition

Contributors are recognized in:
- **CHANGELOG.md** - Listed for significant contributions
- **README.md** - Mentioned in acknowledgments
- **GitHub contributors** - Automatically tracked by GitHub
- **Release notes** - Highlighted for major contributions

### Release Process

PdoDb follows [Semantic Versioning](https://semver.org/):
- **MAJOR** (3.0.0) - Breaking changes
- **MINOR** (2.7.0) - New features, backward compatible
- **PATCH** (2.6.1) - Bug fixes, backward compatible

**Release schedule:**
- Releases are made as needed, typically monthly
- Security fixes are released immediately
- Breaking changes are announced in advance

**Release process:**
1. Update CHANGELOG.md with new features/fixes
2. Create annotated git tag with release notes
3. Push tag to trigger GitHub release
4. Update documentation if needed

### Communication

- **GitHub Issues** - For bug reports and feature requests
- **GitHub Discussions** - For general questions and community discussion
- **Pull Requests** - For code contributions and reviews
- **Email** - For security issues: vasiliy@krivoplyas.com

We value all contributions, whether they're code, documentation, testing, or community support. Every contribution helps make PdoDb better for everyone! 🎉

<!-- omit in toc -->
## Attribution
This guide is based on the [contributing.md](https://contributing.md/generator)!
