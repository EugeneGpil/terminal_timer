# make timer 45:00 [message...]  |  make timer continue  |  make timer cancel
# Everything after "timer" is passed to the PHP app inside the container.
.PHONY: timer build

ARGS = $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))

timer:
	@docker compose run --rm timer php app/timer.php $(ARGS)

build:
	docker compose build

# swallow the extra goals (45:00, message words) so make doesn't complain
%:
	@:
