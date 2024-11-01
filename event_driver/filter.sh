#!/bin/bash

F=filters.d
# Check that filters.d exists.
if [ ! -d "$F" ]; then
    echo Directory $F does not exist!
    exit
fi

STRING=$@

# Were we given any string at all?
if [ "$STRING" == "" ]; then
    echo Syntax: ./filter.sh STRING
    exit
fi

# Find all filter files in filters.d
for FILTER in $F/*; do
    if [ ! -f "$FILTER" ];then
	continue
    fi
    STRING=`$FILTER $STRING`
done

if [ "$STRING" == "" ]; then
    # String was rejected. Ignore.
    exit 1
fi

./event.sh $STRING
