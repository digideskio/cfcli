# Cloudflare CLI

This is a simple tool that serves to interact with the Cloudflare API, and produce reports. Especially useful if you are looking to perform reporting across multiple organisations at the same time.

## How to install

```
composer install
```

## How to run

Here is an example that will list all zones that you have access to that begin with the letter 'J'.

```
./cfcli zone:list --email=${CF_EMAIL} --key=${CF_TOKEN} --organization-filter="^J" --format=yaml
```

The organisation filter accepts full regex.

### Debug

You can use the `-vv` argument to log additional information such as the full URLs of the API requests, and the time taken for each one.
