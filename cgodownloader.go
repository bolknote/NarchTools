package main

import (
    r "regexp"
    "fmt"
    "flag"
    "io"
    "io/ioutil"
    "strings"
    "net/http"
    "os"
    "runtime"
    "path"
    "time"
)

const url = `http://%s/Pages/ImageFile.ashx?level=10&x=0&y=0&tileOverlap=1024&id=%s&page=0&XHDOC=&archiveId=1`
const defaulthost="cgaso.regsamarh.ru"

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

func main() {
    N := runtime.NumCPU() * 2

    dir  := flag.String("dir", ".", "directory to output.")
    host := flag.String("host", defaulthost, "address of e-archive")
    from := flag.Int("from", 0, "number of start page")
    to   := flag.Int("to", 0, "number of end page (default - until the end)")
    flag.Parse()

    if flag.NArg() != 1 {
        selfname := path.Base(os.Args[0])

        fmt.Println("Usage: " + selfname +
        " [-dir=<output dir>] [-host=<e-archive address>] [-from=<start page>] [-to=<end page>] <filename>")

        flag.PrintDefaults()
        os.Exit(0)
    }

    name := flag.Arg(0)
    os.MkdirAll(*dir, 0777)

    runtime.GOMAXPROCS(N + 1)

    if content := readFile(name); content != nil {
        ch := make(chan byte, N)

        documents := getArray(content)
        length    := len(documents)
        if *to == 0 || *to >= length {
            *to = length - 1
        }

        if *to < *from {
            fmt.Println("Error: <to> cannot be less than <from>")
            os.Exit(1)
        }

        if *to != 0 || *from != 0 {
            fmt.Printf("Found %d documents (%d to go).\n", length, *to-*from)
        } else {
            fmt.Printf("Found %d documents.\n", length)
        }

        for i := *from; i <= *to; i++ {
            ch <- 1

            go func(id string, index int) {
                name := fmt.Sprintf("%05d.jpg", index)
                copyUrlToFile(fmt.Sprintf(url, *host, id), *dir + "/" + name)
                fmt.Println(name)

                <-ch
            }(documents[i], i)
        }

        for len(ch) > 0 {
            time.Sleep(100 * time.Millisecond)
        }
    }
}