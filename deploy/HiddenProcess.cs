// GUI-subsystem host for laptop PHP services. The child owns an initially hidden
// console, so proc_open descendants inherit it instead of opening visible windows.
using System;
using System.ComponentModel;
using System.IO;
using System.Runtime.InteropServices;
using System.Text;

internal static class HiddenProcess
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct StartupInfo
    {
        public int cb;
        public string reserved, desktop, title;
        public int x, y, xSize, ySize, xCount, yCount, fill, flags;
        public short show, reservedSize;
        public IntPtr reservedPtr, input, output, error;
    }
    [StructLayout(LayoutKind.Sequential)]
    private struct ProcessInfo { public IntPtr process, thread; public int pid, tid; }
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern bool CreateProcess(string application, StringBuilder command, IntPtr processAttributes,
        IntPtr threadAttributes, bool inherit, uint flags, IntPtr environment, string cwd, ref StartupInfo startup, out ProcessInfo info);
    [DllImport("kernel32.dll")] private static extern IntPtr GetStdHandle(int id);
    [DllImport("kernel32.dll")] private static extern IntPtr GetCurrentProcess();
    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool DuplicateHandle(IntPtr sourceProcess, IntPtr source, IntPtr targetProcess, out IntPtr target, uint access, bool inherit, uint options);
    [DllImport("kernel32.dll")] private static extern uint WaitForSingleObject(IntPtr handle, uint milliseconds);
    [DllImport("kernel32.dll")] private static extern bool GetExitCodeProcess(IntPtr handle, out uint code);
    [DllImport("kernel32.dll")] private static extern bool CloseHandle(IntPtr handle);
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern uint SearchPath(string search, string file, string extension, int size, StringBuilder result, IntPtr filePart);

    private static IntPtr Inheritable(int id)
    {
        IntPtr result;
        if (!DuplicateHandle(GetCurrentProcess(), GetStdHandle(id), GetCurrentProcess(), out result, 0, true, 2))
            throw new Win32Exception(Marshal.GetLastWin32Error());
        return result;
    }

    // Windows CRT argv quoting: no shell expansion, including trailing slashes.
    private static string Quote(string value)
    {
        var result = new StringBuilder("\"");
        int slashes = 0;
        foreach (char character in value)
        {
            if (character == '\\') { slashes++; continue; }
            if (character == '"') result.Append('\\', slashes * 2 + 1);
            else result.Append('\\', slashes);
            slashes = 0;
            result.Append(character);
        }
        return result.Append('\\', slashes * 2).Append('"').ToString();
    }

    private static int Main(string[] args)
    {
        if (args.Length == 0) return 64;
        var startup = new StartupInfo();
        var info = new ProcessInfo();
        try
        {
            if (!Path.IsPathRooted(args[0]))
            {
                var executable = new StringBuilder(32768);
                uint length = SearchPath(null, args[0], ".exe", executable.Capacity, executable, IntPtr.Zero);
                if (length == 0 || length >= executable.Capacity) throw new Win32Exception(Marshal.GetLastWin32Error());
                args[0] = executable.ToString();
            }
            startup.cb = Marshal.SizeOf(typeof(StartupInfo));
            startup.flags = 0x100 | 0x1; // STARTF_USESTDHANDLES | STARTF_USESHOWWINDOW
            startup.show = 0; // SW_HIDE before console creation, never hide after showing.
            startup.input = Inheritable(-10);
            startup.output = Inheritable(-11);
            startup.error = Inheritable(-12);
            var command = new StringBuilder();
            foreach (string arg in args) { if (command.Length > 0) command.Append(' '); command.Append(Quote(arg)); }
            if (!CreateProcess(args[0], command, IntPtr.Zero, IntPtr.Zero, true,
                0x10, IntPtr.Zero, Environment.CurrentDirectory, ref startup, out info)) // CREATE_NEW_CONSOLE
                throw new Win32Exception(Marshal.GetLastWin32Error());
            WaitForSingleObject(info.process, 0xffffffff);
            uint code;
            if (!GetExitCodeProcess(info.process, out code)) throw new Win32Exception(Marshal.GetLastWin32Error());
            return unchecked((int)code);
        }
        catch (Exception exception) { Console.Error.WriteLine("Hidden process: " + exception.Message); return 1; }
        finally
        {
            foreach (IntPtr handle in new[] { startup.input, startup.output, startup.error, info.thread, info.process })
                if (handle != IntPtr.Zero) CloseHandle(handle);
        }
    }
}
