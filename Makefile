clean:
	rm contributors.json
	rm contributors_prs.json
	rm gh_loginsWOCompany.json
	rm gh_pullrequests.json
	rm gh_repositories.json
	rm newcontributors.json
	rm topcompanies.json
	rm topcompanies_prs.json
	rm gh_issues.json
	rm gh_pullrequests_all.json
	rm top_reviewers.json
	rm top_issues.json
	rm top_pullrequests.json

generate:
	php bin/console traces:fetch:repositories --config="./config.dist.yml"
	php bin/console traces:fetch:contributors --config="./config.dist.yml"
	php bin/console traces:fetch:pullrequests:merged
	php bin/console traces:fetch:pullrequests:all
	php bin/console traces:fetch:issues
	php bin/console traces:generate:newcontributors --limitNew=10 --config="./config.dist.yml"
	php bin/console traces:generate:topcompanies --config="./config.dist.yml"
	php bin/console traces:generate:topstats --config="./config.dist.yml"
