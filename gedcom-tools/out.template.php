<html>
<meta charset="utf-8">
<style>
body {
	font: 14px/20px Arial;
}

card {
	text-align: center;
	font: 10px Arial;
	width: 20px;
	height: 70px;
	display: inline-block;
	padding: 8px 2px;
	-webkit-writing-mode: vertical-rl;
	writing-mode: vertical-rl;
	float: right;
}

family {
	margin: 5px 0;
	display: inline-block;
	width: 65px;
}

family::before {
	width: 1px; height: 1px;
	display: inline-block;
	counter-increment: gen 1;
	content: counter(gen);
	-webkit-writing-mode: vertical-rl;
	writing-mode: vertical-rl;
	font-size: 7px;
	position: relative;
	margin-left: calc(65px + 7px);
}

bd {
	display: block;
	font-size: 7px;
}

card[sex=M] {
	background: #a1d5ea;
	outline: 4px solid #a1d5ea;
}

card[sex=F] {
	background: #f4b9da;
	outline: 4px solid #f4b9da;
}

card[sex="?"] {
	background: #fff;
	outline: 4px solid #a1d5ea;
	padding-right: 10px;
}

card[sex=M]+card[sex=F] {
	outline-color: #a1d5ea;
}

card[sex=F]+card[sex=M] {
	outline-color: #f4b9da;
}

chain {
	counter-reset: gen 0;
	display: block;
	margin-bottom: 30px;
}
</style>
<body>
<?=$OUT?>
</body>
</html>