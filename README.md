## Using the sender

```
sender \
  --archive=<path to archive.tar> \
  --version="$(date +%s)" \
  --url=<url to send to> \
  --private-key=<path to private key> \
  --public-key=<path to public key> \
  --client-cert=<path to mtls client certificate> \
  --client-key=<path to mtls client key> \
```

## Structure of archive.tar:
```
servers.ini
<server_name1>/<config files>
<server_name2>/<config files>
```

Building the sender executable:
```
docker compose up -d --build web
docker compose cp web:/usr/local/bin/sender ./sender
docker compose stop web
```
