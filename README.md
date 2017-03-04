# Cloudflare CLI

This is a simple tool that serves to interact with the Cloudflare API, and produce reports. Especially useful if you are looking to perform reporting across multiple organisations at the same time.

## How to install

```
composer install
```

## How to run

Here is an example that will list all zones that you have access to that begin with the letter 'J' (case insenstive).

```
./cfcli zone:list --email=${CF_EMAIL} --key=${CF_TOKEN} --organization-filter="^[jJ]" --format=yaml
```

The organisation filter accepts full regex syntax, allowing you to make very complex filters.

### Check WAF status

If you pass in the additional `--waf` argument, then an additional API request for every zone in the report will be done to check the current statue of the WAF. This will add approximately an additional second per zone to the total time to create the report.

### Debug

You can use the `-v` argument to log additional information such as the full URLs of the API requests, and the time taken for each one.
