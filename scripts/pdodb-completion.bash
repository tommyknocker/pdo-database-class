#!/usr/bin/env bash
# Bash completion script for PDOdb CLI tool
#
# Installation:
#   source this file in your ~/.bashrc or ~/.bash_profile:
#   source /path/to/pdodb-completion.bash
# Or copy to /etc/bash_completion.d/pdodb-completion
#
# Usage:
#   After installation, completion will work automatically when you type:
#   pdodb <TAB>
#   vendor/bin/pdodb <TAB>

_pdodb() {
    local cur prev opts cmd subcmd
    COMPREPLY=()
    cur="${COMP_WORDS[COMP_CWORD]}"
    prev="${COMP_WORDS[COMP_CWORD-1]}"
    cmd="${COMP_WORDS[1]}"
    subcmd="${COMP_WORDS[2]}"

    # Global options available for all commands
    local global_opts="--help --connection= --config= --env="

    # Main commands
    local commands="migrate seed schema query model repository service db user table dump monitor cache init"

    # If no command yet, complete with commands
    if [[ ${COMP_CWORD} -eq 1 ]]; then
        COMPREPLY=($(compgen -W "${commands} ${global_opts}" -- "${cur}"))
        return 0
    fi

    # Handle global options
    if [[ "${prev}" == "--connection" ]] || [[ "${prev}" == "--config" ]] || [[ "${prev}" == "--env" ]]; then
        COMPREPLY=($(compgen -f -- "${cur}"))
        return 0
    fi

    # Migrate command
    if [[ "${cmd}" == "migrate" ]]; then
        local migrate_subcommands="create up down history new help"
        
        if [[ ${COMP_CWORD} -eq 2 ]]; then
            COMPREPLY=($(compgen -W "${migrate_subcommands} --help" -- "${cur}"))
        elif [[ "${subcmd}" == "create" ]] && [[ ${COMP_CWORD} -eq 3 ]]; then
            # Suggest migration name
            COMPREPLY=($(compgen -f -- "${cur}"))
        else
            local migrate_opts="--dry-run --pretend --force"
            COMPREPLY=($(compgen -W "${migrate_opts}" -- "${cur}"))
        fi
        return 0
    fi

    # Seed command
    if [[ "${cmd}" == "seed" ]]; then
        local seed_subcommands="create run list rollback help"
        
        if [[ ${COMP_CWORD} -eq 2 ]]; then
            COMPREPLY=($(compgen -W "${seed_subcommands} --help" -- "${cur}"))
        elif [[ "${subcmd}" == "create" ]] && [[ ${COMP_CWORD} -eq 3 ]]; then
            # Suggest seed name
            COMPREPLY=($(compgen -f -- "${cur}"))
        elif [[ "${subcmd}" == "run" ]] && [[ ${COMP_CWORD} -eq 3 ]]; then
            # Complete with available seed names
            _pdodb_complete_seeds
        elif [[ "${subcmd}" == "rollback" ]] && [[ ${COMP_CWORD} -eq 3 ]]; then
            # Complete with executed seed names
            _pdodb_complete_executed_seeds
        else
            local seed_opts="--dry-run --pretend --force"
            COMPREPLY=($(compgen -W "${seed_opts}" -- "${cur}"))
        fi
        return 0
    fi

    # Schema command
    if [[ "${cmd}" == "schema" ]]; then
        local schema_subcommands="inspect"
        
        if [[ ${COMP_CWORD} -eq 2 ]]; then
            COMPREPLY=($(compgen -W "${schema_subcommands} --help" -- "${cur}"))
        elif [[ "${subcmd}" == "inspect" ]]; then
            if [[ ${COMP_CWORD} -eq 3 ]]; then
                # Complete table names (if possible)
                _pdodb_complete_tables
            else
                local schema_opts="--format=table --format=json --format=yaml --format"
                COMPREPLY=($(compgen -W "${schema_opts}" -- "${cur}"))
            fi
        fi
        return 0
    fi

    # Query command
    if [[ "${cmd}" == "query" ]]; then
        local query_subcommands="test explain"
        
        if [[ ${COMP_CWORD} -eq 2 ]]; then
            COMPREPLY=($(compgen -W "${query_subcommands} --help" -- "${cur}"))
        else
            local query_opts="--format=table --format=json --format"
            COMPREPLY=($(compgen -W "${query_opts}" -- "${cur}"))
        fi
        return 0
    fi

    # Model command
    if [[ "${cmd}" == "model" ]]; then
        local model_subcommands="make"
        
        if [[ ${COMP_CWORD} -eq 2 ]]; then
            COMPREPLY=($(compgen -W "${model_subcommands} --help" -- "${cur}"))
        elif [[ "${subcmd}" == "make" ]] && [[ ${COMP_CWORD} -eq 3 ]]; then
            # Suggest model name
            COMPREPLY=($(compgen -f -- "${cur}"))
        else
            local model_opts="--connection= --table= --namespace= --force"
            COMPREPLY=($(compgen -W "${model_opts}" -- "${cur}"))
        fi
        return 0
    fi

    # Repository command
    if [[ "${cmd}" == "repository" ]]; then
        local repository_subcommands="make"
        
        if [[ ${COMP_CWORD} -eq 2 ]]; then
            COMPREPLY=($(compgen -W "${repository_subcommands} --help" -- "${cur}"))
        elif [[ "${subcmd}" == "make" ]] && [[ ${COMP_CWORD} -eq 3 ]]; then
            # No specific suggestions for repository name
            COMPREPLY=()
        else
            local repository_opts="--force --namespace= --model-namespace= --connection="
            COMPREPLY=($(compgen -W "${repository_opts}" -- "${cur}"))
        fi
        return 0
    fi

    # Service command
    if [[ "${cmd}" == "service" ]]; then
        local service_subcommands="make"
        
        if [[ ${COMP_CWORD} -eq 2 ]]; then
            COMPREPLY=($(compgen -W "${service_subcommands} --help" -- "${cur}"))
        elif [[ "${subcmd}" == "make" ]] && [[ ${COMP_CWORD} -eq 3 ]]; then
            # No specific suggestions for service name
            COMPREPLY=()
        else
            local service_opts="--force --namespace= --repository-namespace= --connection="
            COMPREPLY=($(compgen -W "${service_opts}" -- "${cur}"))
        fi
        return 0
    fi

    # Database command
    if [[ "${cmd}" == "db" ]]; then
        local db_subcommands="create drop list exists"
        
        if [[ ${COMP_CWORD} -eq 2 ]]; then
            COMPREPLY=($(compgen -W "${db_subcommands} --help" -- "${cur}"))
        elif [[ "${subcmd}" == "create" ]] || [[ "${subcmd}" == "drop" ]] || [[ "${subcmd}" == "exists" ]]; then
            if [[ ${COMP_CWORD} -eq 3 ]]; then
                # Suggest database name
                COMPREPLY=($(compgen -f -- "${cur}"))
            else
                local db_opts="--force --if-exists"
                COMPREPLY=($(compgen -W "${db_opts}" -- "${cur}"))
            fi
        fi
        return 0
    fi

    # User command
    if [[ "${cmd}" == "user" ]]; then
        local user_subcommands="create drop list grant revoke"
        
        if [[ ${COMP_CWORD} -eq 2 ]]; then
            COMPREPLY=($(compgen -W "${user_subcommands} --help" -- "${cur}"))
        else
            local user_opts="--force --if-exists"
            COMPREPLY=($(compgen -W "${user_opts}" -- "${cur}"))
        fi
        return 0
    fi

    # Table command
    if [[ "${cmd}" == "table" ]]; then
        local table_subcommands="info list exists create drop rename truncate describe count sample select columns indexes keys"
        
        if [[ ${COMP_CWORD} -eq 2 ]]; then
            COMPREPLY=($(compgen -W "${table_subcommands} --help" -- "${cur}"))
        elif [[ "${subcmd}" == "list" ]]; then
            local table_opts="--schema= --format=table --format=json --format=yaml --format"
            COMPREPLY=($(compgen -W "${table_opts}" -- "${cur}"))
        elif [[ "${subcmd}" == "count" ]]; then
            if [[ ${COMP_CWORD} -eq 3 ]]; then
                # Complete table names (no options for count command)
                _pdodb_complete_tables
            else
                # Count command has no options
                COMPREPLY=()
            fi
        elif [[ "${subcmd}" == "info" ]] || [[ "${subcmd}" == "exists" ]] || \
             [[ "${subcmd}" == "drop" ]] || [[ "${subcmd}" == "truncate" ]] || \
             [[ "${subcmd}" == "describe" ]] || [[ "${subcmd}" == "columns" ]] || \
             [[ "${subcmd}" == "indexes" ]] || [[ "${subcmd}" == "keys" ]] || \
             [[ "${subcmd}" == "rename" ]]; then
            if [[ ${COMP_CWORD} -eq 3 ]]; then
                # Complete table names
                _pdodb_complete_tables
            else
                local table_opts="--format=table --format=json --format=yaml --format --force --if-exists"
                COMPREPLY=($(compgen -W "${table_opts}" -- "${cur}"))
            fi
        elif [[ "${subcmd}" == "sample" ]] || [[ "${subcmd}" == "select" ]]; then
            if [[ ${COMP_CWORD} -eq 3 ]]; then
                # Complete table names
                _pdodb_complete_tables
            else
                local table_opts="--format=table --format=json --format=yaml --format --limit="
                COMPREPLY=($(compgen -W "${table_opts}" -- "${cur}"))
            fi
        elif [[ "${subcmd}" == "create" ]]; then
            if [[ ${COMP_CWORD} -eq 3 ]]; then
                # Suggest table name
                COMPREPLY=($(compgen -f -- "${cur}"))
            else
                local table_opts="--force --if-not-exists"
                COMPREPLY=($(compgen -W "${table_opts}" -- "${cur}"))
            fi
        fi
        return 0
    fi

    # Dump command
    if [[ "${cmd}" == "dump" ]]; then
        if [[ ${COMP_CWORD} -eq 2 ]]; then
            if [[ "${cur}" == "restore" ]] || [[ "${cur}" == restore* ]]; then
                COMPREPLY=($(compgen -W "restore" -- "${cur}"))
            else
                # Complete table names or subcommands
                local dump_subcommands="restore"
                COMPREPLY=($(compgen -W "${dump_subcommands}" -- "${cur}"))
                COMPREPLY+=($(_pdodb_complete_tables))
            fi
        elif [[ "${subcmd}" == "restore" ]] && [[ ${COMP_CWORD} -eq 3 ]]; then
            # Complete SQL files
            COMPREPLY=($(compgen -f -X "!*.sql" -- "${cur}"))
        else
            local dump_opts="--output= --schema-only --data-only --no-drop-tables --force"
            COMPREPLY=($(compgen -W "${dump_opts}" -- "${cur}"))
            
            # Handle --output= completion
            if [[ "${prev}" == "--output" ]] || [[ "${cur}" == --output=* ]]; then
                COMPREPLY=($(compgen -f -X "!*.sql" -- "${cur#--output=}"))
            fi
        fi
        return 0
    fi

    # Monitor command
    if [[ "${cmd}" == "monitor" ]]; then
        local monitor_subcommands="queries connections slow stats"
        
        if [[ ${COMP_CWORD} -eq 2 ]]; then
            COMPREPLY=($(compgen -W "${monitor_subcommands} --help" -- "${cur}"))
        else
            if [[ "${subcmd}" == "queries" ]] || [[ "${subcmd}" == "connections" ]]; then
                local monitor_opts="--watch --format=table --format=json --format"
                COMPREPLY=($(compgen -W "${monitor_opts}" -- "${cur}"))
            elif [[ "${subcmd}" == "slow" ]]; then
                local monitor_opts="--watch --threshold= --limit= --format=table --format=json --format"
                COMPREPLY=($(compgen -W "${monitor_opts}" -- "${cur}"))
            elif [[ "${subcmd}" == "stats" ]]; then
                local monitor_opts="--format=table --format=json --format"
                COMPREPLY=($(compgen -W "${monitor_opts}" -- "${cur}"))
            fi
        fi
        return 0
    fi

    # Cache command
    if [[ "${cmd}" == "cache" ]]; then
        local cache_subcommands="clear invalidate stats"
        
        if [[ ${COMP_CWORD} -eq 2 ]]; then
            COMPREPLY=($(compgen -W "${cache_subcommands} --help" -- "${cur}"))
        else
            if [[ "${subcmd}" == "clear" ]]; then
                local cache_opts="--force"
                COMPREPLY=($(compgen -W "${cache_opts}" -- "${cur}"))
            elif [[ "${subcmd}" == "invalidate" ]]; then
                if [[ ${COMP_CWORD} -eq 3 ]]; then
                    # Pattern argument - no completions for now
                    COMPREPLY=()
                else
                    local cache_opts="--force"
                    COMPREPLY=($(compgen -W "${cache_opts}" -- "${cur}"))
                fi
            elif [[ "${subcmd}" == "stats" ]]; then
                local cache_opts="--format=table --format=json --format"
                COMPREPLY=($(compgen -W "${cache_opts}" -- "${cur}"))
            fi
        fi
        return 0
    fi

    # Init command
    if [[ "${cmd}" == "init" ]]; then
        local init_opts="--skip-connection-test --force --env-only --config-only --no-structure"
        COMPREPLY=($(compgen -W "${init_opts} --help" -- "${cur}"))
        return 0
    fi

    # Default: complete with global options
    COMPREPLY=($(compgen -W "${global_opts}" -- "${cur}"))
    return 0
}

# Helper function to complete table names
_pdodb_complete_tables() {
    local tables
    
    # Try to get table list from pdodb if possible
    # This is optional and may not work in all environments
    if command -v pdodb >/dev/null 2>&1 || command -v vendor/bin/pdodb >/dev/null 2>&1; then
        local pdodb_cmd
        if command -v pdodb >/dev/null 2>&1; then
            pdodb_cmd="pdodb"
        else
            pdodb_cmd="vendor/bin/pdodb"
        fi
        
        # Try to get tables (this might fail if database is not configured)
        tables=$(${pdodb_cmd} table list --format=json 2>/dev/null | \
            grep -o '"name":"[^"]*"' | \
            cut -d'"' -f4 2>/dev/null)
    fi
    
    # If we got tables, return them, otherwise return empty
    if [[ -n "${tables}" ]]; then
        echo "${tables}"
    fi
}

# Helper function to complete seed names
_pdodb_complete_seeds() {
    local seeds
    
    # Try to get seed list from pdodb if possible
    if command -v pdodb >/dev/null 2>&1 || command -v vendor/bin/pdodb >/dev/null 2>&1; then
        local pdodb_cmd
        if command -v pdodb >/dev/null 2>&1; then
            pdodb_cmd="pdodb"
        else
            pdodb_cmd="vendor/bin/pdodb"
        fi
        
        # Try to get seeds (this might fail if database is not configured)
        seeds=$(${pdodb_cmd} seed list 2>/dev/null | \
            grep -E '^\[' | \
            awk '{print $2}' 2>/dev/null)
    fi
    
    # If we got seeds, return them
    if [[ -n "${seeds}" ]]; then
        COMPREPLY=($(compgen -W "${seeds}" -- "${cur}"))
    fi
}

# Helper function to complete executed seed names
_pdodb_complete_executed_seeds() {
    local seeds
    
    # Try to get executed seed list from pdodb if possible
    if command -v pdodb >/dev/null 2>&1 || command -v vendor/bin/pdodb >/dev/null 2>&1; then
        local pdodb_cmd
        if command -v pdodb >/dev/null 2>&1; then
            pdodb_cmd="pdodb"
        else
            pdodb_cmd="vendor/bin/pdodb"
        fi
        
        # Try to get executed seeds (this might fail if database is not configured)
        seeds=$(${pdodb_cmd} seed list 2>/dev/null | \
            grep -E '^\[EXECUTED\]' | \
            awk '{print $2}' 2>/dev/null)
    fi
    
    # If we got seeds, return them
    if [[ -n "${seeds}" ]]; then
        COMPREPLY=($(compgen -W "${seeds}" -- "${cur}"))
    fi
}

# Register completion function
complete -F _pdodb pdodb vendor/bin/pdodb

# Also register for common paths
if [[ -f "./vendor/bin/pdodb" ]] || [[ -f "../vendor/bin/pdodb" ]]; then
    complete -F _pdodb ./vendor/bin/pdodb
    complete -F _pdodb ../vendor/bin/pdodb
fi

