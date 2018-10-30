#!/usr/bin/env bash

set -e

if [[ $CI_MESSAGE != *"--skip-test"* ]]
then
  vendor/bin/phpunit
  if [ $? -ne 0 ]
  then
    echo "Phpunit Tests Failed" >&2
    exit 1
  fi
else
  echo "Skipping Tests"
fi
