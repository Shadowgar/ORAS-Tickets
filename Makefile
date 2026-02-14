# Makefile shortcuts for prompt workflow

.PHONY: prompt-assemble update-master enable-hooks disable-hooks

SESSION ?= 

prompt-assemble:
	@if [ -z "$(SESSION)" ]; then echo "Specify SESSION=prompts/sessions/your.md"; exit 1; fi
	python3 tools/assemble_prompt.py --session $(SESSION) > ready_prompt.txt
	@echo "ready_prompt.txt created"

update-master:
	@if [ -z "$(SESSION)" ]; then echo "Specify SESSION=prompts/sessions/your.md"; exit 1; fi
	python3 tools/update_master_prompt.py $(SESSION)
	@echo "MASTER_PROMPT.md updated"

enable-hooks:
	git config core.hooksPath .githooks && echo "git hooks enabled (core.hooksPath set to .githooks)"

disable-hooks:
	git config --unset core.hooksPath && echo "git hooks disabled"
