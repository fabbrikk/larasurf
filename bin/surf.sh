#!/usr/bin/env bash

set -e

ERROR='\033[91m'
SUCCESS='\033[92m'
RESET='\033[0m'

export SURF_USER_ID=${UID}

function exit_if_containers_not_running() {
  CONTAINERS_RUNNING="$(docker-compose ps -q)"

  if [[ -z "$CONTAINERS_RUNNING" ]]; then
    echo -e "${ERROR}Containers are not running!${RESET}"

    exit 1
  fi
}

if [[ "$1" == 'tls' ]]; then
  if [[ -n "$(which mkcert.exe)" ]]; then
      mkcert.exe -install && mkcert.exe -key-file .docker/tls/local.pem -cert-file .docker/tls/local.crt localhost
    elif [[ -n "$(which mkcert)" ]]; then
      mkcert -install && mkcert -key-file .docker/tls/local.pem -cert-file .docker/tls/local.crt localhost
    else
      echo -e "${ERROR}To use local TLS, please install mkcert from: https://github.com/FiloSottile/mkcert${RESET}"
      exit 1
    fi
elif [[ "$1" == 'composer' ]]; then
  cd $(pwd)
  docker-compose run --rm --no-deps laravel composer "${@:2}"
elif [[ "$1" == 'yarn' ]]; then
  cd $(pwd)
  docker-compose run --rm --no-deps laravel yarn "${@:2}"
elif [[ "$1" == 'npx' ]]; then
  cd $(pwd)
  docker-compose run --rm --no-deps laravel npx "${@:2}"
elif [[ "$1" == 'node' ]]; then
  cd $(pwd)
  docker-compose run --rm --no-deps laravel node "${@:2}"
elif [[ "$1" == 'aws' ]]; then
  docker-compose run --rm awscliv2 "${@:2}"
elif [[ "$1" == 'awslocal' ]]; then
  exit_if_containers_not_running

  NETWORK_NAME="$(echo "$(basename $(pwd))_default" | sed s/[^[:alnum:]_-]//g)"

  docker run --rm -it --network="$NETWORK_NAME" -e AWS_DEFAULT_REGION=us-east-1 -e AWS_ACCESS_KEY_ID=local -e AWS_SECRET_ACCESS_KEY=local amazon/aws-cli:2.0.6 --endpoint http://awslocal:4566 "${@:2}"
elif [[ "$1" == 'artisan' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel php artisan "${@:2}"
elif [[ "$1" == 'publish' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:publish "${@:2}"
elif [[ "$1" == 'config' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:config "${@:2}"
elif [[ "$1" == 'cloud-vars' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:cloud-vars "${@:2}"
elif [[ "$1" == 'cloud-stacks' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:cloud-stacks "${@:2}"
elif [[ "$1" == 'cloud-emails' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:cloud-emails "${@:2}"
elif [[ "$1" == 'cloud-ingress' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:cloud-ingress "${@:2}"
elif [[ "$1" == 'cloud-users' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:cloud-users "${@:2}"
elif [[ "$1" == 'cloud-domains' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:cloud-domains "${@:2}"
elif [[ "$1" == 'cloud-images' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:cloud-images "${@:2}"
elif [[ "$1" == 'cloud-users' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:cloud-users "${@:2}"
elif [[ "$1" == 'cloud-artisan' ]] && [[ "$2" == 'tinker' ]]; then
  if [[ "$3" != '--environment' ]] || [[ "$4" != 'stage' ]] && [[ "$4" != 'production' ]]; then
    echo -e "${ERROR}Invalid environment specified${RESET}"

    exit 1
  fi

  exit_if_containers_not_running

  echo "Waiting for task to start..."

  cd $(pwd)
  TASK=$(docker-compose exec laravel php artisan larasurf:cloud-tasks run-for-exec --environment "$4")
  PROJECT_NAME=$(cat larasurf.json | jq -r '."project-name"')
  PROJECT_ID=$(cat larasurf.json | jq -r '."project-id"')
  CLUSTER_NAME="larasurf-${PROJECT_ID}-$4"

  sleep 5

  cd $(pwd)
  docker-compose run --rm awscliv2 ecs execute-command \
    --cluster ${CLUSTER_NAME} \
    --container artisan \
    --command "php artisan tinker" \
    --interactive \
    --task ${TASK}

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:cloud-tasks stop --environment "$4" --task "${TASK}"
elif [[ "$1" == 'cloud-artisan' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:cloud-artisan "${@:2}"
elif [[ "$1" == 'circleci' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:circleci "${@:2}"
elif [[ "$1" == 'test' ]]; then
  exit_if_containers_not_running

  cd $(pwd)
  docker-compose exec laravel ./vendor/bin/phpunit "${@:2}"
elif [[ "$1" == 'fix' ]]; then
  exit_if_containers_not_running

  if grep -q '"barryvdh/laravel-ide-helper"' 'composer.json'; then
    cd $(pwd)
    docker-compose exec laravel bash -c 'php artisan ide-helper:generate && php artisan ide-helper:meta && php artisan ide-helper:models --write-mixin'
  fi

  if grep -q '"friendsofphp/php-cs-fixer"' 'composer.json'; then
    cd $(pwd)
    docker-compose exec laravel ./vendor/bin/php-cs-fixer fix
  fi
elif [[ "$1" == 'fresh' ]]; then
  REFRESH_COMMAND='php artisan migrate'

  if [[ "$2" == '--seed' ]]; then
    REFRESH_COMMAND="$REFRESH_COMMAND --seed"
  elif [[ -n "$2" ]]; then
    echo -e "${ERROR}Unrecognized option '$2'${RESET}"

    exit 1
  fi

  if [[ -f '.env' ]]; then
    DB_PORT=$(cat .env | grep SURF_DB_PORT= | sed s/SURF_DB_PORT=//)
  fi

  if [[ -z "$DB_PORT" ]]; then
    DB_PORT=3306
  fi

  cd $(pwd)
  docker-compose down --volumes
  cd $(pwd)
  docker-compose up -d

  until curl localhost:$DB_PORT --http0.9 --output /dev/null --silent
  do
      {
        echo 'Waiting for database to be ready...'
        ((COUNT++)) && ((COUNT==20)) && echo -e "${ERROR}Could not connect to database after 20 tries!${RESET}" && exit 1
        sleep 3
      } 1>&2
  done

  echo 'Database is ready!'

  cd $(pwd)
  docker-compose exec laravel $REFRESH_COMMAND
elif [[ "$1" == 'up' ]]; then
  cd $(pwd)

  if [[ "$2" == '--attach' ]]; then
    docker-compose up
  else
    docker-compose up -d
  fi
elif [[ "$1" == 'down' ]]; then
  cd $(pwd)

  if [[ "$2" == '--preserve' ]]; then
    docker-compose down
  else
    docker-compose down --volumes
  fi
elif [[ "$1" == 'rebuild' ]]; then
  cd $(pwd)

  docker-compose build "${@:2}"
elif [[ "$1" == 'configure-new-environments' ]]; then
  exit_if_containers_not_running

  if [[ "$2" != '--environments' ]] || [[ "$3" != 'stage-production' ]] && [[ "$3" != 'production' ]] && [[ "$3" != 'stage' ]]; then
    echo -e "${ERROR}Invalid environments specified${RESET}"

    exit 1
  fi

  cd $(pwd)

  if ! docker-compose exec laravel php artisan larasurf:configure-new-environments validate-new-environments --environments "$3"; then
    exit 1
  fi

  NEW_BRANCHES=$(docker-compose exec laravel php artisan larasurf:configure-new-environments get-new-branches --environments "$3")

  cd $(pwd)

  if [[ "$NEW_BRANCHES" == 'stage-develop' ]]; then
    git checkout -b stage
    cd $(pwd)
    git checkout -b develop
  elif [[ "$NEW_BRANCHES" == 'stage' ]]; then
    git checkout -b stage
    cd $(pwd)
    git checkout develop
  elif [[ "$NEW_BRANCHES" == 'develop' ]]; then
    git checkout -b develop
  else
    exit 1
  fi

  cd $(pwd)
  docker-compose exec laravel php artisan larasurf:configure-new-environments modify-larasurf-config --environments "$3"
else
  # todo
  echo 'See: https://larasurf.com/docs'
fi
