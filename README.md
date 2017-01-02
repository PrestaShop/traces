# Traces

Traces is a micro CLI application that is able to get all contributors and their contributors in 
"developer-readable" JSON format for a specified repository.
 
 
## Installation
 
The authentication is a basic login/password for GitHub.
 
```bash
 $ composer require prestashop/traces
 
 $ ./vendor/bin/traces <repositoryOwner/repositoryName> <login> <password>
```
 
A file named ``contributors.js`` will be generated, you can manipulate it using any programming language.
