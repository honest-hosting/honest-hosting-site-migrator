#
# Makefile for honest-hosting-site-migrator WordPress plugin
#
SHELL := /bin/bash

export ENVIRONMENT ?= localdev

PLUGIN_NAME := honest-hosting-site-migrator

default: help
.PHONY: default

help: ## Display this help screen (default)
	@grep -h "##" $(MAKEFILE_LIST) | grep -v grep | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}' | sort
.PHONY: help

test: export TEST_TYPE ?= test:unit
test: export TEST      ?=
test: test-setup ## Invoke unit or integration tests: TEST="tests/Unit/BasicTest.php" TEST_TYPE=test:unit make test
	@composer $(TEST_TYPE) $(TEST)
.PHONY: test

test-setup:
	@docker compose up -d --wait
.PHONY: test-setup

test-cleanup:
	@docker compose down || true
.PHONY: test-cleanup

lint: fmt ## Run code style checks and static analysis: make lint
	@composer cs:check
	@composer analyze:phpstan
	@composer analyze:phpmd
.PHONY: lint

fmt: ## Auto-fix code style issues: make fmt
	@composer cs:fix
.PHONY: fmt

build: export VERSION     ?= $(if $(CI_COMMIT_REF_NAME),$(CI_COMMIT_REF_NAME),vLOCALDEV)
build: export BUILD_DATE  ?= $(if $(CI_JOB_STARTED_AT),$(CI_JOB_STARTED_AT),$(shell date --rfc-3339=s))
build: export COMMIT_HASH ?= $(if $(CI_COMMIT_SHA),$(CI_COMMIT_SHA),$(shell git rev-parse --short HEAD))
build: build-setup composer-install lint ## Build distributable zip artifact
	@if [[ -n "${VERSION}" ]] && [[ ${VERSION} =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then                                                                                \
		sed -i "s|Version: 1.0.0|Version: $(VERSION)|; s|'HH_MIGRATOR_VERSION', '1.0.0'|'HH_MIGRATOR_VERSION', '$(VERSION)'|;" honest-hosting-site-migrator.php; \
	fi
	@rsync -av \
		--exclude 'build/'              \
		--exclude 'docs/'               \
		--exclude '.git/'               \
		--exclude '.gitignore'          \
		--exclude '.gitmodules'         \
		--exclude 'node_modules/'       \
		--exclude 'tests/'              \
		--exclude '*.env*'              \
		--exclude '*.phpunit*'          \
		--exclude 'phpunit.xml'         \
		--exclude 'TESTING.md'          \
		--exclude 'docker-compose.yml'  \
		--exclude 'Makefile'            \
		./ build/$(PLUGIN_NAME)/
	@pushd build >/dev/null && zip -r $(PLUGIN_NAME)-$(VERSION).zip $(PLUGIN_NAME) >/dev/null
	@pushd build >/dev/null && sha256sum $(PLUGIN_NAME)-$(VERSION).zip > $(PLUGIN_NAME)-$(VERSION).zip.sha256
.PHONY: build

build-setup:
	@if [[ ! -d "build" ]]; then    \
		mkdir build;                \
	fi
	@if [[ ! -e "./docs/openapi.yaml" ]]; then                                       \
		curl -so ./docs/openapi.yaml http://localhost:8080/swagger/file/default.yaml; \
	fi
.PHONY: build-setup

deploy: export SFTP_HOSTNAME ?= localhost
deploy: export SFTP_PORT     ?= 40000
deploy: export SFTP_USERNAME ?= administrator
deploy: export SFTP_PASSWORD ?= dead-beef
deploy: ## Deploy the build dir to destination WP instance: make deploy
	@pushd build >/dev/null ; [[ ! -d "$(PLUGIN_NAME)" ]] && unzip $(PLUGIN_NAME)-vLOCALDEV.zip >/dev/null ; popd >/dev/null
	@sshpass -p '$(SFTP_PASSWORD)' scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -P $(SFTP_PORT) -r build/$(PLUGIN_NAME)/* $(SFTP_USERNAME)@$(SFTP_HOSTNAME):/wp-content/plugins/$(PLUGIN_NAME)/
.PHONY: deploy

clean: test-cleanup ## Remove build artifacts and vendor directory
	@rm -rf build >/dev/null || true
	@rm -rf vendor >/dev/null || true
.PHONY: clean

composer-update: ## Run composer update
	@echo "Running composer update in $(ENVIRONMENT) environment..."
	@if [[ "$(ENVIRONMENT)" == "localdev" ]]; then  \
		composer update;                            \
	fi
.PHONY: composer-update

composer-install: ## Run composer install
	@echo "Running composer install in $(ENVIRONMENT) environment..."
	@if [[ "$(ENVIRONMENT)" == "localdev" ]]; then           \
		composer install;                                    \
	else                                                     \
		composer install --no-dev --optimize-autoloader;     \
	fi
.PHONY: composer-install
