daemon="--rm"
if [ $1 != "" ]; then
  daemon="-d"
fi
dns=$(nmcli --fields ip4.dns con show "Wired connection 1" | head -1 | tr -s " " | cut -d' ' -f2)
docker build -t dominos_voucher_puppet . &&\
docker run --name dominos $daemon --dns $dns -it --sysctl net.ipv6.conf.all.disable_ipv6=1 \
  --expose 9229 --shm-size 1G -p 9229:9229 dominos_voucher_puppet
