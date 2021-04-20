<?php
namespace MPQueue\OutPut;

/**
 * 命令行输出字符格式化类
 * Class FormatOutput
 * @package MPQueue\OutPut
 */
class FormatOutput
{
    private $label = '';
    private $content;
    private $outFile = null;

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    /**
     * 设置输出到文件/直接输出
     * @param null $outFile 文件地址/null(直接输出)
     */
    public function setOutFile($outFile = null){
        $this->outFile = $outFile;
        return $this;
    }

    /**
     * 设置粗体/增强
     */
    public function strong()
    {
        $this->label.= '1;';
        return $this;
    }

    /**
     * 设置斜体(未广泛支持。有时视为反相显示。)
     */
    public function italic()
    {
        $this->label.= '3;';
        return $this;
    }

    /**
     * 上划线
     */
    public function overline(){
        $this->label.= '53;';
        return $this;
    }

    /**
     * 中划线（未广泛支持）
     */
    public function lineThrough()
    {
        $this->label.= '9;';
        return $this;
    }

    /**
     * 下划线
     */
    public function underline()
    {
        $this->label.= '4;';
        return $this;
    }

    /**
     * 缓慢闪烁（低于每分钟150次）
     */
    public function slowBlink()
    {
        $this->label.= '5;';
        return $this;
    }

    /**
     * 快速闪烁（未广泛支持）
     */
    public function fastBlink()
    {
        $this->label.= '6;';
        return $this;
    }

    /**
     * 设置字体颜色/前景色(rgb色值 默认为白色)
     * @param int $r 红 0-255
     * @param int $g 绿 0-255
     * @param int $b 蓝 0-255
     */
    public function color(int $r = 255, int $g = 255, int $b = 255)
    {
        $this->label.= "38;2;$r;$g;$b;";
        return $this;
    }

    /**
     * 设置背景景色(rgb色值 默认为黑色)
     * @param int $r 红 0-255
     * @param int $g 绿 0-255
     * @param int $b 蓝 0-255
     */
    public function backgroundColor(int $r = 0, int $g = 0, int $b = 0)
    {
        $this->label.= "48;2;$r;$g;$b;";
        return $this;

    }

    /**
     * 输出内容
     */
    public function outPut(){
        if($this->outFile){
            file_put_contents($this->outFile,$this->getFormatContent(),FILE_APPEND | LOCK_EX);
        }else{
            echo $this->getFormatContent();
        }
    }

    /**
     * 获取格式化后的输出内容
     * @return string
     */
    public function getFormatContent(): string
    {
        $this->label = rtrim($this->label,';');
        return "\e[{$this->label}m{$this->content}\e[0m";
    }

    /**
     * 静态调用快速设置内容
     * @param string $content
     * @return FormatOutput
     */
    public static function setContent(string $content)
    {
        return new self($content);
    }
}