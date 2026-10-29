SOURCE_FILES?=./...
TEST_PATTERN?=.
TEST_OPTIONS?=

export PATH := ./bin:$(PATH)
export GO111MODULE := on
GOPROXY ?= https://proxy.golang.org,direct
export GOPROXY

# Build a beta version of goreleaser
build:
	go build cmd/nps/nps.go
	go build cmd/npc/npc.go
.PHONY: build

# Install all the build and lint dependencies
setup:
	curl -sfL https://install.goreleaser.com/github.com/golangci/golangci-lint.sh | sh
	curl -L https://git.io/misspell | sh
	go mod download
.PHONY: setup

# Run all the tests
test:
	go test $(TEST_OPTIONS) -failfast -race -coverpkg=./... -covermode=atomic -coverprofile=coverage.txt $(SOURCE_FILES) -run $(TEST_PATTERN) -timeout=2m
.PHONY: test

# xnps 扩展包测试（上游 lib/pmux 等历史用例不参与）
test-ext:
	go test ./server/ext/... ./lib/acmechallenge/... ./lib/changelog/...
.PHONY: test-ext

# nps-admin PHPUnit（需先 composer install）
test-admin:
	cd nps-admin && php artisan test
.PHONY: test-admin

# 全平台构建打包（产物 dist/*.tar.gz + sha256sums.txt）
release:
	./build-xnps.sh
.PHONY: release

# Run all the tests and opens the coverage report
cover: test
	go tool cover -html=coverage.txt
.PHONY: cover

# gofmt and goimports all go files
fmt:
	find . -name '*.go' -not -wholename './vendor/*' | while read -r file; do gofmt -w -s "$$file"; goimports -w "$$file"; done
.PHONY: fmt

# Run all the linters
lint:
	# TODO: fix tests and lll issues
	./bin/golangci-lint run --tests=false --enable-all --disable=lll ./...
	./bin/misspell -error **/*
.PHONY: lint

# Clean go.mod
go-mod-tidy:
	@go mod tidy -v
	@git diff HEAD
	@git diff-index --quiet HEAD
.PHONY: go-mod-tidy

# Run all the tests and code checks
ci: build test lint go-mod-tidy
.PHONY: ci

# Generate the static documentation
static:
	@hugo --enableGitInfo --source www
.PHONY: static

# Show to-do items per file.
todo:
	@grep \
		--exclude-dir=vendor \
		--exclude-dir=node_modules \
		--exclude=Makefile \
		--text \
		--color \
		-nRo -E ' TODO:.*|SkipNow' .
.PHONY: todo

clean:
	rm npc nps
.PHONY: clean

.DEFAULT_GOAL := build
