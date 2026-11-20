IMAGE ?= claimsys-legacy

.PHONY: build dbinit run gen seed docker-build docker-run docker-web clean

# Local targets need: php-cli php-mysql mariadb-server python3 (Ubuntu 24.04 packages)
build:
	@for f in batch/*.php inc/*.php web/*.php; do php -l $$f > /dev/null || exit 1; done
	@php -m | grep -qi mysqli || (echo "php-mysql is not installed"; exit 1)
	@mkdir -p output spool
	@echo "CLAIMSYS sources check out. Database: run 'make dbinit' once."

# one-off: create the claimsys database and the batch account
dbinit:
	sudo mariadb < db/bootstrap.sql

run: build
	CLAIMSYS_HOME="$(CURDIR)" ./run/RUNADJUD.sh data/CLAIMS.DAT output/$$(date +%Y%m%d_%H%M%S)

gen:
	python3 tools/gen_claims.py --count 10000 --seed 42 --out data/CLAIMS.DAT

seed:
	python3 tools/gen_seed.py --out db/seed.sql

docker-build:
	docker build -t $(IMAGE) .

# run the nightly job once on the sample file; output appears in ./output/<timestamp>/
docker-run:
	mkdir -p output
	docker run --rm -v "$(CURDIR)/output:/opt/claimsys/output" $(IMAGE) ./run/RUNADJUD.sh

# intranet pages on http://localhost:8080
docker-web:
	docker run --rm -p 8080:8080 $(IMAGE)

clean:
	rm -rf output spool/KEYED.DAT
