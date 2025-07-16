# Traces

Traces is a Symfony CLI application that is able to :
* fetch all repositories of the PrestaShop organization
* fetch all contributors and their contributions in "developer-readable" JSON format for a specified repository
* fetch all merged pull requests of the PrestaShop organization
 
## Installation
 
The authentication use a Github Token.

> Note: You can fetch here : https://github.com/settings/tokens/new?description=traces&scopes=repo,read:org
 
```bash
 $ composer require prestashop/traces

 # Fetch all repositories
 $ php bin/console traces:fetch:repositories --ghtoken=<ghtoken>
 OR
 $ GH_TOKEN=<ghtoken> php bin/console traces:fetch:repositories
 OR
 * Add GH_TOKEN=<ghtoken> in .env file
 $ php bin/console traces:fetch:repositories
 ## A file gh_repositories.json is generated
 
 # Check a repository
 $ php bin/console traces:fetch:contributors --ghtoken=<ghtoken> -r <repositoryName> --config="config.yml"
 OR
 $ GH_TOKEN=<ghtoken> php bin/console traces:fetch:contributors -r <repositoryName> --config="config.yml"
 OR
 * Add GH_TOKEN=<ghtoken> in .env file
 $ php bin/console traces:fetch:contributors -r <repositoryName> --config="config.yml"
 ## A file contributors.json is generated

 # Fetch all merged pullrequests
 $ php bin/console traces:fetch:pullrequests:merged --ghtoken=<ghtoken>
 OR
 $ GH_TOKEN=<ghtoken> php bin/console traces:fetch:pullrequests:merged
 OR
 * Add GH_TOKEN=<ghtoken> in .env file
 $ php bin/console traces:fetch:pullrequests:merged
 ## A file gh_pullrequests.json is generated
```

## Configuring
 
There are a number of settings that can be configured via the config file. Take a look at the `config.dist.yml` file for an example.

Option             | Description
-------------------|-------------------------------------------------------------------------------------------
exclusions         | List of excluded users.
keepExcludedUsers  | Set to `true` to flag excluded contributors instead of filtering them out from the output.
fieldsWhitelist    | List of fields to keep from the API result. Leave blank if you want to keep them all.
extractEmailDomain | Set to `true` to extract the user's email domain and include it in the generated file

## License

This project is released under the MIT license.
