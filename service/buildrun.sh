daemon="--rm"
if [ $1 != "" ]; then
  daemon="-d"
fi
#export site=$(docker inspect lamp | jq -r '.[].NetworkSettings.Networks.bridge.IPAddress')
echo "Starting bot linked to site $site_host"
#dns=$(nmcli --fields ip4.dns con show "Wired connection 1" | head -1 | tr -s " " | cut -d' ' -f2)
docker build -t dominos_voucher_puppet . &&\
docker run -e site_host --name dominos $daemon -it --sysctl net.ipv6.conf.all.disable_ipv6=1 \
  --expose 9229 --shm-size 1G --net dominos --ip 172.18.0.2  -p 9229:9229 -p 3000:3000 \
  dominos_voucher_puppet
