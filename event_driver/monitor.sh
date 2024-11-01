#!/bin/bash

CONF="conf.d/monitor.conf"
FILTER=./filter.sh

if [ ! -f "$CONF" ]; then
    echo The config file $CONF was not found.
    exit
fi

source "$CONF"

if [ ! -f "$FILTER" ]; then
    echo The filter, $FILTER, was not found in the current directory.
    exit
fi

# Start multitailing the file.
multitail -f ${MULTITAIL_OPTIONS} -ex "$REGEXP" $FILTER $FILE
