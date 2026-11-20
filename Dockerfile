FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      mariadb-server mariadb-client php-cli php-mysql python3 util-linux \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/claimsys
COPY db/ db/
COPY inc/ inc/
COPY batch/ batch/
COPY run/ run/
COPY tools/ tools/
COPY data/ data/
COPY ctl/ ctl/
COPY web/ web/

RUN cp db/claimsys.cnf /etc/mysql/mariadb.conf.d/99-claimsys.cnf \
 && mkdir -p output spool \
 && chmod +x run/*.sh \
 && for f in batch/*.php inc/*.php web/*.php; do php -l "$f" > /dev/null || exit 1; done \
 && chown -R www-data:www-data output spool

ENV CLAIMSYS_HOME=/opt/claimsys
EXPOSE 8080
CMD ["./run/ENTRYPOINT.sh"]
