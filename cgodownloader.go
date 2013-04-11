package main

import (
    r "regexp"
    // "fmt"
    "flag"
    "io"
    "io/ioutil"
    "strings"
    "net/http"
    "strconv"
    "os"
    "runtime"
    "path"
)

const url = `http://cgaso.regsamarh.ru/Pages/ImageFile.ashx?level=12&x=0&y=0&tileSize=25600&tileOverlap=1&id=%id&page=0&XHDOC=&archiveId=1`

func copyUrlToFile(url, filename string) bool {
    if resp, err := http.Get(url); err == nil {
        defer resp.Body.Close()

        if w, err := os.OpenFile(filename, os.O_WRONLY|os.O_TRUNC|os.O_CREATE, 0666); err == nil {
            defer w.Close()

            if _, err := io.Copy(w, resp.Body); err == nil {
                return true
            }
        }
    }

    return false
}

func readFile(name string) []byte {
    if bytes, err := ioutil.ReadFile(name); err == nil {
        return bytes
    }

    return nil
}


func getArray(content []byte) (array []string) {
    re, _ := r.Compile(`(?m)^dxo\.itemsValue=\[('[\w-]{3,}.*?')\];$`)
    matches := re.FindSubmatch(content)

    if len(matches) > 0 {
        array = strings.Split(string(matches[1]), ",")

        for index, value := range array {
            array[index] = strings.Trim(value, "'")
        }
    }

    return
}

func padZero5(val string) string {
    if len := len(val); len < 5 {
        return ("00000" + val)[len:]
    }

    return val
}

func main() {
    N := runtime.NumCPU() * 2

    dir := flag.String("dir", ".", "directory to output.")
    flag.Parse()

    if flag.NArg() != 1 {
        selfname := path.Base(os.Args[0])

        os.Stdout.WriteString("Usage: " + selfname + " [-dir=<output dir>] <filename>\n")

        flag.PrintDefaults()
        os.Exit(0)
    }

    name := flag.Arg(0)
    os.MkdirAll(*dir, 0777)

    runtime.GOMAXPROCS(N + 1)

    if content := readFile(name); content != nil {
        ch := make(chan byte, N)

        documents := getArray(content)

        os.Stdout.WriteString("Found " + strconv.Itoa(len(documents)) + " documents.\n")

        for i, id := range documents {
            ch <- 1

            go func(id string, index int) {
                name := padZero5(strconv.Itoa(index)) + ".jpg"
                copyUrlToFile(strings.Replace(url, `%id`, id, 1), *dir + "/" + name)
                os.Stdout.WriteString(name + "\n")

                <-ch
            }(id, i)
        }
    }
}