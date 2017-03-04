# Cloudflare CLI

## How to install

```
composer install
```

## How to run

Here is an example that will list all zones that you have access to that begin with the letter 'J'.

```
./cfcli zone:list --email=${CF_EMAIL} --key=${CF_TOKEN} --organization-filter="^J" --format=yaml
```
