import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/ruta_model.dart';
import '../services/local_storage_service.dart';

class GastoItem {
  String tipoGasto;
  String metodoPago;
  TextEditingController montoController;
  TextEditingController observacionesController;
  File? foto;

  GastoItem({
    this.tipoGasto = 'Comida / Alimentos',
    this.metodoPago = 'Efectivo',
    TextEditingController? montoController,
    TextEditingController? observacionesController,
    this.foto,
  })  : montoController = montoController ?? TextEditingController(),
        observacionesController = observacionesController ?? TextEditingController();
}

class CucaItem {
  TextEditingController numeroCucaController;
  TextEditingController observacionesController;
  List<File> fotos;

  CucaItem({
    TextEditingController? numeroCucaController,
    TextEditingController? observacionesController,
    List<File>? fotos,
  })  : numeroCucaController = numeroCucaController ?? TextEditingController(),
        observacionesController = observacionesController ?? TextEditingController(),
        fotos = fotos ?? [];
}

class RegistrarParadaScreen extends StatefulWidget {
  final RutaProgramada ruta;
  final Destino destino;
  final String baseUrl;

  const RegistrarParadaScreen({
    super.key,
    required this.ruta,
    required this.destino,
    required this.baseUrl,
  });

  @override
  State<RegistrarParadaScreen> createState() => _RegistrarParadaScreenState();
}

class _RegistrarParadaScreenState extends State<RegistrarParadaScreen> with WidgetsBindingObserver {
  final List<GastoItem> _listaGastos = [];
  final List<CucaItem> _listaCucas = [];
  final ImagePicker _picker = ImagePicker();
  bool _guardando = false;
  bool _isExiting = false;

  // 🛡️ Control del autoguardado del borrador en archivo JSON local.
  bool _isSaving = false;
  bool _pendingSave = false;
  bool _borradorListo = false;
  bool _cargandoBorrador = true;
  bool _borradorEliminado = false;
  late File _archivoBorrador;

  final List<String> _tiposGasto = [
    'Comida / Alimentos',
    'Hotel / Hospedaje',
    'Casetas / Peaje',
    'Estacionamiento',
    'Gasolina / Diesel',
    'Otros',
  ];

  final List<String> _formasPago = [
    'Efectivo',
    'Tarjeta',
  ];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _inicializarBorradorLocal();
    _revisarFotoPerdida();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.detached ||
        state == AppLifecycleState.inactive) {
      _guardarBorradorLocalSync();
    }
    super.didChangeAppLifecycleState(state);
  }

  String get _borradorKey =>
      'borrador_parada_${widget.ruta.id}_${widget.destino.id}';

  // 🛡️ Método para rescatar imágenes capturadas si la app fue destruida por Android
  Future _revisarFotoPerdida() async {
    try {
      final LostDataResponse response = await _picker.retrieveLostData();
      if (response.isEmpty || response.file == null) return;

      final appDir = await getApplicationDocumentsDirectory();
      final fileName = 'recuperado_${DateTime.now().millisecondsSinceEpoch}.jpg';
      final savedImage = await File(response.file!.path).copy('${appDir.path}/$fileName');

      if (!mounted) return;
      setState(() {
        for (var gasto in _listaGastos.reversed) {
          if (gasto.foto == null) {
            gasto.foto = savedImage;
            break;
          }
        }
      });
      _guardarBorradorLocal();
    } catch (e) {
      debugPrint('Error recuperando foto perdida por baja RAM: $e');
    }
  }

  Future _inicializarBorradorLocal() async {
    try {
      final directory = await getApplicationDocumentsDirectory();

      _archivoBorrador = File(
        '${directory.path}/borrador_parada_${widget.ruta.id}_${widget.destino.id}.json',
      );

      await _cargarBorradorLocal();

      _borradorListo = true;

      if (_pendingSave && !_borradorEliminado) {
        _pendingSave = false;
        await _guardarBorradorLocal();
      }
    } catch (e) {
      debugPrint('Error inicializando borrador local: $e');
    } finally {
      if (mounted) {
        setState(() => _cargandoBorrador = false);
      } else {
        _cargandoBorrador = false;
      }
    }
  }

  Map _crearDatosBorrador() {
    return {
      'version': 2,
      'fecha_guardado': DateTime.now().toIso8601String(),
      'cucas': _listaCucas
          .map(
            (c) => {
              'numero': c.numeroCucaController.text,
              'observaciones': c.observacionesController.text,
              'fotos': c.fotos.map((f) => f.path).toList(),
            },
          )
          .toList(),
      'gastos': _listaGastos
          .map(
            (g) => {
              'tipo': g.tipoGasto,
              'pago': g.metodoPago,
              'monto': g.montoController.text,
              'observaciones': g.observacionesController.text,
              'foto': g.foto?.path ?? '',
            },
          )
          .toList(),
    };
  }

  Future _guardarBorradorLocal() async {
    if (_borradorEliminado) return;

    if (!_borradorListo) {
      _pendingSave = true;
      return;
    }

    if (_isSaving) {
      _pendingSave = true;
      return;
    }

    _isSaving = true;

    final String data = jsonEncode(_crearDatosBorrador());

    try {
      await _archivoBorrador.parent.create(recursive: true);
      await _archivoBorrador.writeAsString(data, flush: true);
    } catch (e) {
      debugPrint('Error guardando borrador en archivo: $e');
    } finally {
      _isSaving = false;

      if (_pendingSave && !_borradorEliminado) {
        _pendingSave = false;
        await _guardarBorradorLocal();
      }
    }
  }

  void _guardarBorradorLocalSync() {
    if (_borradorEliminado || !_borradorListo) return;

    try {
      final String data = jsonEncode(_crearDatosBorrador());
      _archivoBorrador.parent.createSync(recursive: true);
      _archivoBorrador.writeAsStringSync(data, flush: true);
      debugPrint('Borrador guardado de forma síncrona al cambiar el estado de la app.');
    } catch (e) {
      debugPrint('Error en guardado síncrono del borrador: $e');
    }
  }

  Future _cargarBorradorLocal() async {
    try {
      String? dataString;
      bool vieneDeSharedPreferences = false;

      if (await _archivoBorrador.exists()) {
        dataString = await _archivoBorrador.readAsString();
      }

      if (dataString == null || dataString.trim().isEmpty) {
        final prefs = await SharedPreferences.getInstance();
        dataString = prefs.getString(_borradorKey);
        vieneDeSharedPreferences = dataString != null;
      }

      if (dataString == null || dataString.trim().isEmpty) {
        return;
      }

      final dynamic decoded = jsonDecode(dataString);
      if (decoded is! Map) {
        debugPrint('El borrador local no tiene un formato válido.');
        return;
      }

      final Map data = Map.from(decoded);

      if (!mounted) return;

      setState(() {
        _listaCucas.clear();
        _listaGastos.clear();

        final dynamic cucasData = data['cucas'];
        if (cucasData is List) {
          for (final dynamic item in cucasData) {
            if (item is! Map) continue;

            final CucaItem cuca = CucaItem();
            cuca.numeroCucaController.text =
                item['numero']?.toString() ?? '';
            cuca.observacionesController.text =
                item['observaciones']?.toString() ?? '';

            final dynamic fotosData = item['fotos'];
            if (fotosData is List) {
              for (final dynamic pathValue in fotosData) {
                final String path = pathValue?.toString() ?? '';
                if (path.isEmpty) continue;

                final File foto = File(path);
                if (foto.existsSync()) {
                  cuca.fotos.add(foto);
                }
              }
            }

            _listaCucas.add(cuca);
          }
        }

        final dynamic gastosData = data['gastos'];
        if (gastosData is List) {
          for (final dynamic item in gastosData) {
            if (item is! Map) continue;

            final GastoItem gasto = GastoItem(
              tipoGasto:
                  item['tipo']?.toString() ?? 'Comida / Alimentos',
              metodoPago: item['pago']?.toString() ?? 'Efectivo',
            );

            gasto.montoController.text =
                item['monto']?.toString() ?? '';
            gasto.observacionesController.text =
                item['observaciones']?.toString() ?? '';

            final String fotoPath = item['foto']?.toString() ?? '';
            if (fotoPath.isNotEmpty) {
              final File foto = File(fotoPath);
              if (foto.existsSync()) {
                gasto.foto = foto;
              }
            }

            _listaGastos.add(gasto);
          }
        }
      });

      if (vieneDeSharedPreferences) {
        try {
          await _archivoBorrador.parent.create(recursive: true);
          await _archivoBorrador.writeAsString(dataString, flush: true);

          final prefs = await SharedPreferences.getInstance();
          await prefs.remove(_borradorKey);
        } catch (e) {
          debugPrint('No se pudo migrar el borrador anterior: $e');
        }
      }

      if (mounted && (_listaCucas.isNotEmpty || _listaGastos.isNotEmpty)) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('♻️ Borrador anterior recuperado exitosamente.'),
            backgroundColor: Colors.blue,
          ),
        );
      }
    } catch (e) {
      debugPrint('Error cargando borrador local: $e');
    }
  }

  Future _limpiarBorradorLocal() async {
    _borradorEliminado = true;
    _pendingSave = false;

    try {
      if (_borradorListo && await _archivoBorrador.exists()) {
        await _archivoBorrador.delete();
      }
    } catch (e) {
      debugPrint('Error eliminando archivo de borrador: $e');
    }

    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_borradorKey);
    } catch (e) {
      debugPrint('Error limpiando respaldo antiguo del borrador: $e');
    }
  }

  void _agregarGasto() {
    setState(() => _listaGastos.add(GastoItem()));
    _guardarBorradorLocal();
  }

  void _eliminarGasto(int index) {
    setState(() {
      _listaGastos[index].montoController.dispose();
      _listaGastos[index].observacionesController.dispose();
      _listaGastos.removeAt(index);
    });
    _guardarBorradorLocal();
  }

  void _agregarCuca() {
    setState(() => _listaCucas.add(CucaItem()));
    _guardarBorradorLocal();
  }

  void _eliminarCuca(int index) {
    setState(() {
      _listaCucas[index].numeroCucaController.dispose();
      _listaCucas[index].observacionesController.dispose();
      _listaCucas.removeAt(index);
    });
    _guardarBorradorLocal();
  }

  Future _tomarFoto(int index) async {
    try {
      await _guardarBorradorLocal();

      final XFile? photo = await _picker.pickImage(
        source: ImageSource.camera,
        maxWidth: 1000,
        maxHeight: 1000,
        imageQuality: 50,
      );

      if (photo != null) {
        final appDir = await getApplicationDocumentsDirectory();
        final fileName = 'gasto_${DateTime.now().millisecondsSinceEpoch}.jpg';
        final savedImage = await File(photo.path).copy('${appDir.path}/$fileName');

        if (!mounted) return;
        setState(() => _listaGastos[index].foto = savedImage);
        _guardarBorradorLocal();
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error al capturar foto: $e')));
    }
  }

  Future _tomarFotoCuca(int index) async {
    try {
      await _guardarBorradorLocal();

      final XFile? photo = await _picker.pickImage(
        source: ImageSource.camera,
        maxWidth: 1000,
        maxHeight: 1000,
        imageQuality: 50,
      );

      if (photo != null) {
        final appDir = await getApplicationDocumentsDirectory();
        final fileName = 'cuca_${DateTime.now().millisecondsSinceEpoch}.jpg';
        final savedImage = await File(photo.path).copy('${appDir.path}/$fileName');

        if (!mounted) return;
        setState(() => _listaCucas[index].fotos.add(savedImage));
        _guardarBorradorLocal();
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error al capturar foto de QK: $e')));
    }
  }

  void _eliminarFotoCuca(int cucaIndex, int fotoIndex) {
    setState(() {
      _listaCucas[cucaIndex].fotos.removeAt(fotoIndex);
    });
    _guardarBorradorLocal();
  }

  Future _guardarParada({bool sinGastos = false}) async {
    for (int i = 0; i < _listaCucas.length; i++) {
      if (_listaCucas[i].numeroCucaController.text.trim().isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('⚠️ Ingrese el número de QK #${i + 1}')));
        return;
      }
      if (_listaCucas[i].fotos.isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('⚠️ Debe tomar al menos una foto para la QK #${i + 1}')));
        return;
      }
    }

    if (!sinGastos) {
      if (_listaGastos.isEmpty && _listaCucas.isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Agrega al menos un gasto o una QK, o selecciona "Registrar Parada SIN Gastos"')));
        return;
      }

      for (int i = 0; i < _listaGastos.length; i++) {
        if (_listaGastos[i].montoController.text.trim().isEmpty) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('⚠️ Ingrese el monto para el gasto #${i + 1}')));
          return;
        }
      }
    }

    setState(() => _guardando = true);

    try {
      bool enviadoExitosamente = await _enviarAlBackendYServidor(sinGastos: sinGastos);

      if (enviadoExitosamente) {
        await LocalStorageService().marcarParadaCompletada(widget.ruta.id, widget.destino.id);
        await _limpiarBorradorLocal(); 
        await _eliminarArchivosFotosLocales();

        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('✅ Registro enviado correctamente al servidor'), backgroundColor: Colors.green),
          );
          Navigator.pop(context, true);
        }
      } else {
        await _guardarEnAlmacenamientoLocal(sinGastos: sinGastos);
        await LocalStorageService().marcarParadaCompletada(widget.ruta.id, widget.destino.id);
        await _limpiarBorradorLocal(); 

        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('⚠️ Guardado localmente (Sin conexión). Se subirá al sincronizar.'), backgroundColor: Colors.orange),
          );
          Navigator.pop(context, true);
        }
      }
    } catch (e) {
      await _guardarEnAlmacenamientoLocal(sinGastos: sinGastos);
      await LocalStorageService().marcarParadaCompletada(widget.ruta.id, widget.destino.id);
      await _limpiarBorradorLocal(); 

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('⚠️ Guardado localmente por fallo de red: $e'), backgroundColor: Colors.orange),
        );
        Navigator.pop(context, true);
      }
    } finally {
      if (mounted) setState(() => _guardando = false);
    }
  }

  Future _eliminarArchivosFotosLocales() async {
    try {
      for (var gasto in _listaGastos) {
        if (gasto.foto != null && await gasto.foto!.exists()) {
          await gasto.foto!.delete();
        }
      }
      for (var cuca in _listaCucas) {
        for (var foto in cuca.fotos) {
          if (await foto.exists()) {
            await foto.delete();
          }
        }
      }
    } catch (e) {
      debugPrint("Error al limpiar archivos físicos de fotos: $e");
    }
  }

  Future _guardarEnAlmacenamientoLocal({required bool sinGastos}) async {
    for (var cuca in _listaCucas) {
      for (var foto in cuca.fotos) {
        final mapaCuca = {
          'ruta_id': widget.ruta.id,
          'parada_id': widget.destino.id,
          'numero_cuca': cuca.numeroCucaController.text.trim(),
          'observaciones': cuca.observacionesController.text.trim(),
          'foto_cuca': foto.path,
          'fecha': DateTime.now().toIso8601String(),
          'sincronizado': 0,
        };
        await LocalStorageService().guardarCucaPendiente(mapaCuca);
      }
    }

    if (sinGastos) {
      final mapaGasto = {
        'ruta_id': widget.ruta.id,
        'parada_id': widget.destino.id,
        'destino': widget.destino.razonSocial,
        'tipo_gasto': 'Sin Gastos',
        'monto': 0.0,
        'observaciones': 'Parada realizada sin registro de gastos.',
        'foto_comprobante': '',
        'fecha': DateTime.now().toIso8601String(),
        'completada': 1,
        'sincronizado': 0,
      };
      await LocalStorageService().guardarGastoPendiente(mapaGasto);
    } else {
      for (var gasto in _listaGastos) {
        final double monto = double.tryParse(gasto.montoController.text.trim()) ?? 0.0;
        final String observaciones = gasto.observacionesController.text.trim();
        final String fotoPath = gasto.foto?.path ?? '';

        final mapaGasto = {
          'ruta_id': widget.ruta.id,
          'parada_id': widget.destino.id,
          'destino': widget.destino.razonSocial,
          'tipo_gasto': gasto.tipoGasto,
          'metodo_pago': gasto.metodoPago,
          'monto': monto,
          'observaciones': observaciones,
          'foto_comprobante': fotoPath,
          'fecha': DateTime.now().toIso8601String(),
          'completada': 1,
          'sincronizado': 0,
        };
        await LocalStorageService().guardarGastoPendiente(mapaGasto);
      }
    }
  }

  Future _enviarAlBackendYServidor({required bool sinGastos}) async {
    try {
      final String baseUrlLimpia = widget.baseUrl.endsWith('/') ? widget.baseUrl.substring(0, widget.baseUrl.length - 1) : widget.baseUrl;
      final url = Uri.parse('$baseUrlLimpia/api/agregar_parada_chofer.php');
      var request = http.MultipartRequest('POST', url);

      request.fields['parada_id'] = widget.destino.id.toString();
      request.fields['ruta_id'] = widget.ruta.id.toString();
      
      if (!sinGastos) {
        for (var gasto in _listaGastos) {
          final double monto = double.tryParse(gasto.montoController.text.trim()) ?? 0.0;
          
          String keyGasto = 'comida';
          if (gasto.tipoGasto.contains('Hotel')) keyGasto = 'hotel';
          else if (gasto.tipoGasto.contains('Caseta')) keyGasto = 'caseta';
          else if (gasto.tipoGasto.contains('Estacionamiento')) keyGasto = 'estacionamiento';
          else if (gasto.tipoGasto.contains('Gasolina') || gasto.tipoGasto.contains('Diesel')) keyGasto = 'gasolina';

          request.fields['gasto_$keyGasto'] = monto.toString();
          request.fields['metodo_pago_$keyGasto'] = gasto.metodoPago;
          request.fields['observaciones_$keyGasto'] = gasto.observacionesController.text.trim();

          if (gasto.foto != null && await gasto.foto!.exists()) {
            request.files.add(await http.MultipartFile.fromPath('foto_$keyGasto', gasto.foto!.path));
          }
        }
      }

      for (int i = 0; i < _listaCucas.length; i++) {
        final cuca = _listaCucas[i];
        request.fields['numero_cuca_$i'] = cuca.numeroCucaController.text.trim();
        request.fields['observaciones_cuca_$i'] = cuca.observacionesController.text.trim();

        for (int j = 0; j < cuca.fotos.length; j++) {
          final foto = cuca.fotos[j];
          if (await foto.exists()) {
            request.files.add(await http.MultipartFile.fromPath('foto_cuca_${i}_${j}', foto.path));
          }
        }
      }

      var streamedResponse = await request.send().timeout(const Duration(seconds: 20));
      var response = await http.Response.fromStream(streamedResponse);

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true) {
          return true;
        }
      }
      return false;
    } catch (e) {
      debugPrint('⚠️ Error de red al sincronizar con servidor: $e');
      return false;
    }
  }

  @override
  void dispose() {
    _guardarBorradorLocalSync();
    WidgetsBinding.instance.removeObserver(this);
    for (var gasto in _listaGastos) {
      gasto.montoController.dispose();
      gasto.observacionesController.dispose();
    }
    for (var cuca in _listaCucas) {
      cuca.numeroCucaController.dispose();
      cuca.observacionesController.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: _isExiting,
      onPopInvoked: (didPop) async {
        if (didPop) return;
        
        await _guardarBorradorLocal(); 
        
        setState(() {
          _isExiting = true;
        });
        
        if (context.mounted) {
          Navigator.of(context).pop();
        }
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text('Parada: ${widget.destino.sucursal}'),
          backgroundColor: Colors.white,
          foregroundColor: const Color(0xFF4A148C),
          elevation: 1,
        ),
        body: _cargandoBorrador
            ? const Center(
                child: CircularProgressIndicator(
                  color: Color(0xFF4A148C),
                ),
              )
            : SingleChildScrollView(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Card(
                      elevation: 2,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      child: Padding(
                        padding: const EdgeInsets.all(16.0),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(widget.destino.razonSocial, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFF4A148C))),
                            const SizedBox(height: 6),
                            Text('🏢 Sucursal: ${widget.destino.sucursal}'),
                            Text('📍 Dirección: ${widget.destino.direccion}'),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 20),

                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('📄 Facturas / QK Entregadas', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFF2E7D32))),
                        const SizedBox(height: 8),
                        ElevatedButton.icon(
                          onPressed: _agregarCuca,
                          icon: const Icon(Icons.add, size: 18),
                          label: const Text('Agregar QK'),
                          style: ElevatedButton.styleFrom(backgroundColor: Colors.green, foregroundColor: Colors.white),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    if (_listaCucas.isEmpty)
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(16),
                        decoration: BoxDecoration(color: Colors.green[50], borderRadius: BorderRadius.circular(10), border: Border.all(color: Colors.green[200]!)),
                        child: const Center(child: Text('No hay QK (facturas) agregadas.\nPresiona "+ Agregar QK" para registrar las facturas entregadas y selladas.', textAlign: TextAlign.center, style: TextStyle(color: Colors.green, fontSize: 13))),
                      )
                    else
                      ..._listaCucas.asMap().entries.map((entry) {
                        int index = entry.key;
                        CucaItem cuca = entry.value;
                        return _buildTarjetaCuca(index, cuca);
                      }).toList(),

                    const SizedBox(height: 24),

                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('💰 Gastos de Parada', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFF4A148C))),
                        const SizedBox(height: 8),
                        ElevatedButton.icon(
                          onPressed: _agregarGasto,
                          icon: const Icon(Icons.add, size: 18),
                          label: const Text('Agregar Gasto'),
                          style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF4A148C), foregroundColor: Colors.white),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    if (_listaGastos.isEmpty)
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(16),
                        decoration: BoxDecoration(color: Colors.grey[100], borderRadius: BorderRadius.circular(10), border: Border.all(color: Colors.grey[300]!)),
                        child: const Center(child: Text('No hay gastos registrados.\nPuedes registrar la parada sin gastos o presionar "+ Agregar Gasto".', textAlign: TextAlign.center, style: TextStyle(color: Colors.grey, fontSize: 13))),
                      )
                    else
                      ..._listaGastos.asMap().entries.map((entry) {
                        int index = entry.key;
                        GastoItem gasto = entry.value;
                        return _buildTarjetaGasto(index, gasto);
                      }).toList(),

                    const SizedBox(height: 24),
                    
                    if (_guardando)
                      const Center(child: CircularProgressIndicator(color: Color(0xFF4A148C)))
                    else
                      Column(
                        children: [
                          SizedBox(
                            width: double.infinity,
                            child: ElevatedButton.icon(
                              onPressed: () => _guardarParada(sinGastos: false),
                              icon: const Icon(Icons.check_circle),
                              label: Text('✅ Registrar Parada (${_listaCucas.length} QK(s), ${_listaGastos.length} Gasto(s))'),
                              style: ElevatedButton.styleFrom(backgroundColor: Colors.green, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(vertical: 16), textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                            ),
                          ),
                          const SizedBox(height: 12),
                          SizedBox(
                            width: double.infinity,
                            child: OutlinedButton.icon(
                              onPressed: () => _guardarParada(sinGastos: true),
                              icon: const Icon(Icons.do_not_disturb_on_outlined, color: Colors.grey),
                              label: const Text('📝 Registrar Parada SIN Gastos (Solo QK)'),
                              style: OutlinedButton.styleFrom(foregroundColor: Colors.black87, side: const BorderSide(color: Colors.grey, width: 1.5), padding: const EdgeInsets.symmetric(vertical: 16), textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                            ),
                          ),
                        ],
                      ),
                  ],
                ),
              ),
      ),
    );
  }

  Widget _buildTarjetaCuca(int index, CucaItem cuca) {
    return Card(
      margin: const EdgeInsets.only(bottom: 16.0),
      elevation: 2,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: const BorderSide(color: Colors.green, width: 1.2),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('📄 Factura / QK #${index + 1}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Color(0xFF2E7D32))),
                IconButton(icon: const Icon(Icons.delete_outline, color: Colors.red), onPressed: () => _eliminarCuca(index)),
              ],
            ),
            const SizedBox(height: 8),
            TextFormField(
              controller: cuca.numeroCucaController,
              onChanged: (_) => _guardarBorradorLocal(), 
              decoration: const InputDecoration(
                labelText: 'Número de QK (Factura)*',
                hintText: 'Ej. C-10492',
                border: OutlineInputBorder(),
                prefixIcon: Icon(Icons.receipt),
              ),
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: cuca.observacionesController,
              onChanged: (_) => _guardarBorradorLocal(), 
              decoration: const InputDecoration(
                labelText: 'Observaciones / Detalles de la QK',
                hintText: 'Ej. Entregada sellada en recepción',
                border: OutlineInputBorder(),
                prefixIcon: Icon(Icons.comment),
              ),
            ),
            const SizedBox(height: 12),
            
            if (cuca.fotos.isNotEmpty) ...[
              const Text('Páginas capturadas:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: Colors.grey)),
              const SizedBox(height: 8),
              SizedBox(
                height: 100,
                child: ListView.builder(
                  scrollDirection: Axis.horizontal,
                  itemCount: cuca.fotos.length,
                  itemBuilder: (context, fotoIdx) {
                    return Stack(
                      children: [
                        Container(
                          margin: const EdgeInsets.only(right: 8),
                          width: 80,
                          height: 100,
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: Colors.green, width: 1.5),
                          ),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(6),
                            child: Image.file(cuca.fotos[fotoIdx], fit: BoxFit.cover),
                          ),
                        ),
                        Positioned(
                          top: 2,
                          right: 10,
                          child: GestureDetector(
                            onTap: () => _eliminarFotoCuca(index, fotoIdx),
                            child: Container(
                              padding: const EdgeInsets.all(2),
                              decoration: const BoxDecoration(color: Colors.red, shape: BoxShape.circle),
                              child: const Icon(Icons.close, color: Colors.white, size: 14),
                            ),
                          ),
                        ),
                        Positioned(
                          bottom: 2,
                          left: 4,
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
                            decoration: BoxDecoration(color: Colors.black.withOpacity(0.7), borderRadius: BorderRadius.circular(4)),
                            child: Text('Pág ${fotoIdx + 1}', style: const TextStyle(color: Colors.white, fontSize: 10)),
                          ),
                        ),
                      ],
                    );
                  },
                ),
              ),
              const SizedBox(height: 12),
            ],

            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                onPressed: () => _tomarFotoCuca(index),
                icon: const Icon(Icons.camera_alt),
                label: Text(cuca.fotos.isEmpty ? 'Tomar Foto de QK Sellada' : '➕ Capturar Otra Página'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.green,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 12),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildTarjetaGasto(int index, GastoItem gasto) {
    return Card(
      margin: const EdgeInsets.only(bottom: 16.0),
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      child: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('Gasto #${index + 1}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Color(0xFF4A148C))),
                IconButton(icon: const Icon(Icons.delete_outline, color: Colors.red), onPressed: () => _eliminarGasto(index)),
              ],
            ),
            const SizedBox(height: 8),
            DropdownButtonFormField<String>(
              value: gasto.tipoGasto,
              decoration: const InputDecoration(labelText: 'Tipo de Gasto / Servicio', border: OutlineInputBorder(), contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 8)),
              items: _tiposGasto.map((tipo) => DropdownMenuItem(value: tipo, child: Text(tipo))).toList(),
              onChanged: (val) { 
                if (val != null) {
                  setState(() => gasto.tipoGasto = val);
                  _guardarBorradorLocal(); 
                } 
              },
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              value: gasto.metodoPago,
              decoration: const InputDecoration(labelText: 'Forma de Pago', border: OutlineInputBorder(), contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 8), prefixIcon: Icon(Icons.payment, size: 20)),
              items: _formasPago.map((forma) => DropdownMenuItem(value: forma, child: Text(forma))).toList(),
              onChanged: (val) { 
                if (val != null) {
                  setState(() => gasto.metodoPago = val); 
                  _guardarBorradorLocal();
                } 
              },
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: gasto.montoController,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              onChanged: (_) => _guardarBorradorLocal(), 
              decoration: const InputDecoration(labelText: 'Monto del gasto (\$)', prefixText: '\$ ', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: gasto.observacionesController,
              onChanged: (_) => _guardarBorradorLocal(), 
              decoration: const InputDecoration(labelText: 'Observaciones / Detalles', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: gasto.foto == null
                  ? ElevatedButton.icon(
                      onPressed: () => _tomarFoto(index),
                      icon: const Icon(Icons.camera_alt),
                      label: const Text('Tomar Foto de Comprobante'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF4A148C),
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 12),
                      ),
                    )
                  : Column(
                      children: [
                        ClipRRect(borderRadius: BorderRadius.circular(10), child: Image.file(gasto.foto!, height: 140, width: double.infinity, fit: BoxFit.cover)),
                        const SizedBox(height: 6),
                        OutlinedButton.icon(
                          onPressed: () => _tomarFoto(index),
                          icon: const Icon(Icons.refresh),
                          label: const Text('Cambiar Foto'),
                          style: OutlinedButton.styleFrom(foregroundColor: const Color(0xFF4A148C)),
                        ),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }
}
