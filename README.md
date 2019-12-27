# Traces

Traces is a micro CLI application that is able to get all contributors and their contributions in 
"developer-readable" JSON format for a specified repository.
 
 
## Installation
 
The authentication is a basic login/password for GitHub.
 
```bash
 $ composer require prestashop/traces
 
 $ ./vendor/bin/traces <repositoryOwner/repositoryName> <login> <password> --config="config.yml"
```

> Note: If your Github login uses two-factor authentication, use an API token instead of password
 
You can configure a list of excluded contributors, take a look to ```config.dist.yml``` file.
 
A file named ``contributors.js`` will be generated, you can manipulate it using any programming language.
