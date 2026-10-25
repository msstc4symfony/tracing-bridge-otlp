PHPSTAN_CONFIG ?= phpstan.dist.neon

check: ## Check code (expects the composer-ci.json install, see install-ci)
	find ./ -name '*.php' -not -path './vendor/*' | xargs -r php -l
	vendor/bin/phpstan analyse --memory-limit=512M -c $(PHPSTAN_CONFIG)
	vendor/bin/php-cs-fixer check
	composer validate --strict --no-check-publish
	composer audit
	vendor/bin/rector process -n
	vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress

test: ## Test code (integration tests may skip themselves without the composer-ci.json install)
	vendor/bin/phpunit

test-unit: ## Test code (unit suite only)
	vendor/bin/phpunit --testsuite=unit

test-integration: ## Test code (integration suite only)
	vendor/bin/phpunit --testsuite=integration

test-with-coverage: ## Test code with coverage
	XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html coverage

infection: ## Run mutation testing
	XDEBUG_MODE=coverage vendor/bin/infection --threads=$(shell nproc) --no-interaction

install-ci: ## Install the CI profile: optional libraries plus CI-only tools
	COMPOSER=composer-ci.json composer install --prefer-dist --no-progress --no-interaction

regenerate-baseline: ## Regenerate baseline
	vendor/bin/phpstan analyse --memory-limit=512M -b phpstan-baseline.neon

fix: ## Fix code
	vendor/bin/php-cs-fixer fix
	vendor/bin/rector process

## Help
help: ## List of all commands
	@grep -E '(^[a-zA-Z_0-9-]+:.*?##.*$$)|(^##)' Makefile \
	| awk 'BEGIN {FS = ":.*?## "}; {printf "${G}%-24s${NC} %s\n", $$1, $$2}' \
	| sed -e 's/\[32m## /[33m/' && printf "\n";

.DEFAULT_GOAL := help
.PHONY: check test test-unit test-integration test-with-coverage infection install-ci regenerate-baseline fix help
