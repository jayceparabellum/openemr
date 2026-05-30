FROM openemr/openemr:flex@sha256:f652556da7997eeee983b16afd37c941637d2c2118ddd47b091562ead6f9949b

USER root
WORKDIR /var/www/localhost/htdocs/openemr

COPY --chown=apache:apache . /var/www/localhost/htdocs/openemr

RUN mkdir -p /var/www/localhost/htdocs/openemr/sites \
    /var/www/localhost/htdocs/openemr/public/assets \
    /var/www/localhost/htdocs/openemr/public/themes \
    && chown -R apache:apache /var/www/localhost/htdocs/openemr/sites \
    /var/www/localhost/htdocs/openemr/public/assets \
    /var/www/localhost/htdocs/openemr/public/themes \
    && chmod -R u=rwX,g=rwX,o=rX /var/www/localhost/htdocs/openemr \
    && chmod -R u=rwX,g=rwX,o=rwX /var/www/localhost/htdocs/openemr/sites \
    && chmod -R u=rwX,g=rwX,o=rwX /var/www/localhost/htdocs/openemr/public/assets \
    && chmod -R u=rwX,g=rwX,o=rwX /var/www/localhost/htdocs/openemr/public/themes

WORKDIR /var/www/localhost/htdocs
EXPOSE 80

CMD ["./openemr.sh"]
