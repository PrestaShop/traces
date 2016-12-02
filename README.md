# Traces

Traces is a micro CLI application that is able to get all contributors and their contributors in 
"developer-readable" JSON format for a specified repository.
 
 
## Installation
 
The authentication is a basic login/password for GitHub.
 
```bash
 $ composer install prestashop/traces
 
 $ ./vendor/bin/traces <repository> <login> <password>
```
 
A ``contributor.js`` file will be generated, you can manipulate it using any programming language.

