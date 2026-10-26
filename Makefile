.PHONY: up down sh test ps composer exec

s ?= app

up:
	./bin/dev up $(c)

down:
	./bin/dev down $(c)

sh:
	./bin/dev sh $(c)

test:
	./bin/dev test $(c)

ps:
	./bin/dev ps $(c)

composer:
	./bin/dev composer $(c)

exec:
	./bin/dev exec $(s) $(c)
