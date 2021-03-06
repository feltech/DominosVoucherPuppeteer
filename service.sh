daemon="--rm"
if [ $1 != "" ]; then
  daemon="-d"
fi
#export site=$(docker inspect lamp | jq -r '.[].NetworkSettings.Networks.bridge.IPAddress')
echo "Starting bot linked to site $site_host with $daemon"
#dns=$(nmcli --fields ip4.dns con show "Wired connection 1" | head -1 | tr -s " " | cut -d' ' -f2)
if [[ "$(docker ps -q -f name=dominos)" != "" ]]; then
  docker stop dominos
fi
if [[ "$(docker ps -a -q -f name=dominos)" != "" ]]; then
  docker rm dominos
fi
docker build -t dominos_voucher_puppet service &&\
docker run -e site_host -e bot_secret --name dominos $daemon -it \
  --sysctl net.ipv6.conf.all.disable_ipv6=1 --expose 9229 --shm-size 1G --net dominos \
  --ip 172.18.0.2  -p 9229:9229 -p 3000:3000 dominos_voucher_puppet
