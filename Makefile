# make timer 45:00 [message...]  |  make timer continue  |  make timer cancel
# Everything after "timer" is passed to the PHP app inside the container.
.PHONY: timer build

export TZ := $(shell timedatectl show -p Timezone --value 2>/dev/null)

ARGS = $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))

timer:
	@docker compose run --rm timer php app/timer.php $(ARGS)

build:
	docker compose build

# swallow the extra goals (45:00, message words) so make doesn't complain
%:
	@:
