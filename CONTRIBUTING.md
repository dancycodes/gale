# Contributing to Laravel Gale

Thank you for considering contributing to Laravel Gale! We welcome contributions from the community.

## Code of Conduct

This project and everyone participating in it is governed by our Code of Conduct. By participating, you are expected to uphold this code. Please report unacceptable behavior to [dancycodes@gmail.com](mailto:dancycodes@gmail.com).

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the [issue tracker](https://github.com/dancycodes/gale/issues) as you might find that the issue has already been reported. When creating a bug report, include:

- A clear and descriptive title
- Exact steps to reproduce the problem
- The behavior you observed and what you expected
- Your environment details (PHP version, Laravel version, OS)

### Suggesting Enhancements

Enhancement suggestions are tracked as [GitHub issues](https://github.com/dancycodes/gale/issues). When creating an enhancement suggestion, include:

- A clear and descriptive title
- A detailed description of the suggested enhancement
- Why this enhancement would be useful

### Code Contributions

1. Fork the repository
2. Create a new branch for your feature (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Write or update tests as needed
5. Ensure all tests pass
6. Commit your changes (`git commit -m 'Add amazing feature'`)
7. Push to your branch (`git push origin feature/amazing-feature`)
8. Open a Pull Request

## Development Setup

### Prerequisites

- PHP 8.2 or higher
- Composer
- Git

### Local Development

1. **Clone your fork:**
   ```bash
   git clone https://github.com/YOUR_USERNAME/gale.git
   cd gale/packages/dancycodes/gale
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Run tests:**
   ```bash
   composer test
   ```

### Running Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test-coverage
```

### Coding Standards

We follow PSR-12 and use Laravel Pint for code formatting:

```bash
# Check code style
./vendor/bin/pint --test

# Fix code style
./vendor/bin/pint
```

## Pull Request Process

### Before Submitting

1. All tests pass (`composer test`)
2. Code follows PSR-12 (run Pint)
3. New features have tests
4. Documentation is updated if needed
5. Commit messages are clear

### PR Guidelines

- Use a clear, descriptive title
- Explain what and why in the description
- Link related issues
- Mark any breaking changes

## Getting Help

- **Questions**: Open a [GitHub Discussion](https://github.com/dancycodes/gale/discussions)
- **Bugs**: Create an [Issue](https://github.com/dancycodes/gale/issues)

## License

By contributing to Laravel Gale, you agree that your contributions will be licensed under the MIT License.

---

Thank you for contributing to Laravel Gale!
