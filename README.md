# Traces

Traces is a Symfony CLI application that is able to :
* fetch all repositories of the PrestaShop organization
* fetch all contributors and their contributions in "developer-readable" JSON format for a specified repository
* fetch all merged pull requests of the PrestaShop organization
 
## Installation / usage
 
The authentication use a Github Token.

> Note: You can fetch here : https://github.com/settings/tokens/new?description=traces&scopes=repo,read:org
 
```bash
 $ composer create-project prestashop/traces
```

 All the following commands require a Github token to access and use Github APIs, you have three ways to define this token:
 - using the command option: `--ghtoken=<ghtoken>`
 - using an environment variable: GH_TOKEN=<ghtoken> php bin/console <command>`
 - using .env file and adding GH_TOKEN=<ghtoken>

```bash
 # 1- Fetch all repositories
 $ php bin/console traces:fetch:repositories
 ## A file gh_repositories.json is generated
 
 # 2- Check a repository (the repository option is optional and will then fetch ALL repositories from gh_repositories.json)
 $ php bin/console traces:fetch:contributors -r <repositoryName> --config="config.yml"
 ## A file contributors.json is generated

 # 3- Fetch all merged pullrequests
 $ php bin/console traces:fetch:pullrequests:merged
 ## A file gh_pullrequests.json is generated

 # 4- Fetch new contributors
 $ php bin/console traces:generate:newcontributors --config="config.yml"
 ## A file newcontributors.json is generated

 # 5- Fetch top companies
 $ php bin/console traces:generate:topcompanies --config="config.yml"
 ## Files topcompanies.json and gh_loginsWOCompany.json are generated
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
