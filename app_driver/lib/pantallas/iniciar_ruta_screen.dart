import 'package:flutter/material.dart';
import 'dart:io';
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:geolocator/geolocator.dart';
import 'package:geocoding/geocoding.dart';
import '../models/ruta_model.dart';
import '../services/local_storage_service.dart';

class IniciarRutaScreen extends StatefulWidget {
  final RutaProgramada ruta;
  final String baseUrl;
  final List<String> listaPlacas;
  final List<String> listaNoEconomico;

  const IniciarRutaScreen({
    super.key,
    required this.ruta,
    required this.baseUrl,
    required this.listaPlacas,
    required this.listaNoEconomico,
  });

  @override
  State<IniciarRutaScreen> createState() => _IniciarRutaScreenState();
}

class _IniciarRutaScreenState extends State<IniciarRutaScreen> {
  final TextEditingController _origenCtrl = TextEditingController();
  final TextEditingController _kmInicialCtrl = TextEditingController();

  String _placasSeleccionadas = '';
  String _noEconomicoSeleccionado = '';
  String _ubicacionActual = 'Obteniendo ubicación...';
  File? _fotoInicio;
  bool _cargando = false;
  bool _obteniendoUbicacion = false;
  bool _cambiandoVehiculo = false;
  final ImagePicker _picker = ImagePicker();

  List<String> _placasDisponibles = [];
  List<String> _noEconomicoDisponibles = [];

  @override
  void initState() {
    super.initState();
    _placasDisponibles = ['Cargando...'];
    _noEconomicoDisponibles = ['Cargando...'];
    _placasSeleccionadas = widget.ruta.placas;
    _noEconomicoSeleccionado = widget.ruta.noEconomico;
    _cargarCatalogos();
    _obtenerUbicacionActual();
  }

  @override
  void dispose() {
    _origenCtrl.dispose();
    _kmInicialCtrl.dispose();
    super.dispose();
  }

  Future<void> _cargarCatalogos() async {
    try {
      final url = Uri.parse('${widget.baseUrl}/obtener_catalogos.php');
      final response = await http.get(url).timeout(const Duration(seconds: 10));
      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        final placas = List<String>.from(data['placas'] ?? []);
        final noEconomico = List<String>.from(data['no_economico'] ?? []);
        if (!mounted) return;
        setState(() {
          _placasDisponibles = placas.toSet().toList();
          _noEconomicoDisponibles = noEconomico.toSet().toList();
          if (!_placasDisponibles.contains(_placasSeleccionadas) && _placasDisponibles.isNotEmpty) {
            _placasSeleccionadas = _placasDisponibles.first;
          }
          if (!_noEconomicoDisponibles.contains(_noEconomicoSeleccionado) && _noEconomicoDisponibles.isNotEmpty) {
            _noEconomicoSeleccionado = _noEconomicoDisponibles.first;
          }
        });
      } else {
        _usarCatalogosDefecto();
      }
    } catch (e) {
      _usarCatalogosDefecto();
    }
  }

  void _usarCatalogosDefecto() {
    if (!mounted) return;
    setState(() {
      _placasDisponibles = ['LKW976D', 'MJY388C', 'MKA605C', 'MSB505A', 'NFD381C', 'NTS872C'];
      _noEconomicoDisponibles = ['A-01', 'A-02', 'A-03', 'A-04', 'A-05', 'A-06'];
      if (!_placasDisponibles.contains(_placasSeleccionadas) && _placasDisponibles.isNotEmpty) {
        _placasSeleccionadas = _placasDisponibles.first;
      }
      if (!_noEconomicoDisponibles.contains(_noEconomicoSeleccionado) && _noEconomicoDisponibles.isNotEmpty) {
        _noEconomicoSeleccionado = _noEconomicoDisponibles.first;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Iniciar Ruta #${widget.ruta.id}'),
        backgroundColor: Colors.white,
        foregroundColor: const Color(0xFF4A148C),
        elevation: 1,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _buildInfoRuta(),
            const SizedBox(height: 16),
            _buildOrigenSalida(),
            const SizedBox(height: 16),
            _buildSelectorVehiculo(),
            const SizedBox(height: 16),
            _buildKmInicial(),
            const SizedBox(height: 24),
            _buildBotonIniciar(),
          ],
        ),
      ),
    );
  }

  Widget _buildInfoRuta() {
    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('📋 Ruta Programada', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            if (widget.ruta.numeroRuta.isNotEmpty)
              _buildInfoRow('Número de Ruta', widget.ruta.numeroRuta),
            _buildInfoRow('ID Interno', '#${widget.ruta.id}'),
            _buildInfoRow('Chofer', widget.ruta.chofer),
            _buildInfoRow('Destinos', '${widget.ruta.destinos.length}'),
            _buildInfoRow('Vehículo Asignado', '${widget.ruta.placas} - ${widget.ruta.noEconomico}'),
          ],
        ),
      ),
    );
  }

  Widget _buildOrigenSalida() {
    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(
              children: [
                Icon(Icons.home, size: 20, color: Color(0xFF4A148C)),
                SizedBox(width: 8),
                Text('🏠 Origen de Salida', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Color(0xFF4A148C))),
              ],
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _origenCtrl,
              decoration: const InputDecoration(
                labelText: '¿Desde dónde inicias?',
                hintText: 'Ej: Mi casa, Base, Terminal...',
                border: OutlineInputBorder(),
                prefixIcon: Icon(Icons.location_on),
              ),
            ),
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.blue[50],
                borderRadius: BorderRadius.circular(8),
              ),
              child: Row(
                children: [
                  Icon(_obteniendoUbicacion ? Icons.gps_fixed : Icons.gps_off, color: _obteniendoUbicacion ? Colors.blue : Colors.grey),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _ubicacionActual,
                          style: TextStyle(
                            color: _obteniendoUbicacion ? Colors.blue[800] : Colors.grey[600],
                            fontSize: 13,
                            fontWeight: FontWeight.w500,
                          ),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ],
                    ),
                  ),
                  TextButton.icon(
                    onPressed: _obtenerUbicacionActual,
                    icon: const Icon(Icons.refresh, size: 18),
                    label: const Text('ACTUALIZAR', style: TextStyle(fontSize: 11)),
                    style: TextButton.styleFrom(foregroundColor: const Color(0xFF4A148C)),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            Text('💡 La ubicación GPS se registrará automáticamente', style: TextStyle(fontSize: 11, color: Colors.grey[600])),
          ],
        ),
      ),
    );
  }

  Widget _buildSelectorVehiculo() {
    if (_placasDisponibles.isEmpty || _placasDisponibles.first == 'Cargando...' ||
        _noEconomicoDisponibles.isEmpty || _noEconomicoDisponibles.first == 'Cargando...') {
      return Card(
        elevation: 1,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide(color: Colors.grey[300]!)),
        child: const Padding(
          padding: EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [Icon(Icons.directions_car, size: 20, color: Colors.green), SizedBox(width: 8), Text('🚗 Cargando vehículos...', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.green))]),
              SizedBox(height: 12),
              Center(child: CircularProgressIndicator()),
            ],
          ),
        ),
      );
    }

    final placasUnicas = _placasDisponibles.toSet().toList();
    final noEconomicoUnicos = _noEconomicoDisponibles.toSet().toList();
    
    String placasValue = _placasSeleccionadas;
    if (!placasUnicas.contains(placasValue) && placasUnicas.isNotEmpty) placasValue = placasUnicas.first;
    String noEconomicoValue = _noEconomicoSeleccionado;
    if (!noEconomicoUnicos.contains(noEconomicoValue) && noEconomicoUnicos.isNotEmpty) noEconomicoValue = noEconomicoUnicos.first;

    return Card(
      elevation: 1,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide(color: Colors.grey[300]!)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              const Icon(Icons.directions_car, size: 20, color: Colors.green),
              const SizedBox(width: 8),
              Text('🚗 Seleccionar Vehículo (Opcional)', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.green[700])),
            ]),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  flex: 1,
                  child: DropdownButtonFormField<String>(
                    value: placasValue,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'Placas',
                      border: OutlineInputBorder(),
                      prefixIcon: Icon(Icons.directions_car, size: 18),
                      contentPadding: EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                      isDense: true,
                    ),
                    items: placasUnicas.map((String placa) => DropdownMenuItem<String>(
                      value: placa,
                      child: Text(placa, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 14)),
                    )).toList(),
                    onChanged: (String? newValue) {
                      setState(() {
                        if (newValue != null) {
                          _placasSeleccionadas = newValue;
                          if (newValue != widget.ruta.placas) _cambiandoVehiculo = true;
                        }
                      });
                    },
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  flex: 1,
                  child: DropdownButtonFormField<String>(
                    value: noEconomicoValue,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'No. Económico',
                      border: OutlineInputBorder(),
                      prefixIcon: Icon(Icons.numbers, size: 18),
                      contentPadding: EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                      isDense: true,
                    ),
                    items: noEconomicoUnicos.map((String noEco) => DropdownMenuItem<String>(
                      value: noEco,
                      child: Text(noEco, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 14)),
                    )).toList(),
                    onChanged: (String? newValue) {
                      setState(() {
                        if (newValue != null) {
                          _noEconomicoSeleccionado = newValue;
                          if (newValue != widget.ruta.noEconomico) _cambiandoVehiculo = true;
                        }
                      });
                    },
                  ),
                ),
              ],
            ),
            if (_cambiandoVehiculo)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: Colors.orange[50],
                    borderRadius: BorderRadius.circular(4),
                    border: Border.all(color: Colors.orange[300]!),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.warning, color: Colors.orange, size: 16),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          '⚠️ Cambiando vehículo asignado: ${widget.ruta.placas} → $_placasSeleccionadas',
                          style: TextStyle(color: Colors.orange[800], fontSize: 12),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildKmInicial() {
    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('📸 KM Inicial', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Color(0xFF4A148C))),
            const SizedBox(height: 12),
            TextFormField(
              controller: _kmInicialCtrl,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(
                labelText: 'Kilometraje inicial (km)',
                border: OutlineInputBorder(),
                prefixIcon: Icon(Icons.speed),
                suffixText: 'km',
              ),
            ),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: _fotoInicio == null
                  ? ElevatedButton.icon(
                      onPressed: _tomarFotoInicio,
                      icon: const Icon(Icons.camera_alt),
                      label: const Text('TOMAR FOTO ODÓMETRO'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF4A148C),
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                      ),
                    )
                  : Column(
                      children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(10),
                          child: Image.file(
                            _fotoInicio!,
                            height: 180,
                            width: double.infinity,
                            fit: BoxFit.cover,
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text('✅ Foto tomada correctamente', style: TextStyle(color: Colors.green[700], fontSize: 13)),
                        const SizedBox(height: 8),
                        OutlinedButton.icon(
                          onPressed: _tomarFotoInicio,
                          icon: const Icon(Icons.refresh),
                          label: const Text('Cambiar Foto'),
                          style: OutlinedButton.styleFrom(
                            foregroundColor: const Color(0xFF4A148C),
                          ),
                        ),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildBotonIniciar() {
    return Column(
      children: [
        SizedBox(
          width: double.infinity,
          child: ElevatedButton(
            onPressed: _cargando ? null : _iniciarRuta,
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.green,
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(vertical: 16),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            child: _cargando
                ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(Icons.play_arrow),
                      const SizedBox(width: 8),
                      Text(_cambiandoVehiculo ? '🚀 INICIAR CON OTRO VEHÍCULO' : '🚀 INICIAR RUTA', style: const TextStyle(fontSize: 16)),
                    ],
                  ),
          ),
        ),
        if (_cambiandoVehiculo)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text('Se actualizarán las placas a "$_placasSeleccionadas"', style: TextStyle(color: Colors.orange[700], fontSize: 12)),
          ),
      ],
    );
  }

  void _mostrarMensaje(String texto, Color color) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(texto), backgroundColor: color));
  }

  Widget _buildInfoRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Text('$label: ', style: const TextStyle(fontWeight: FontWeight.w500)),
          Text(value),
        ],
      ),
    );
  }

  Future<void> _tomarFotoInicio() async {
    try {
      final foto = await _picker.pickImage(source: ImageSource.camera, imageQuality: 70);
      if (foto != null) {
        if (!mounted) return;
        setState(() => _fotoInicio = File(foto.path));
      }
    } catch (e) {
      _mostrarMensaje('Error al tomar la foto: $e', Colors.red);
    }
  }

  Future<void> _obtenerUbicacionActual() async {
    setState(() {
      _obteniendoUbicacion = true;
      _ubicacionActual = 'Obteniendo ubicación...';
    });

    try {
      LocationPermission permiso = await Geolocator.checkPermission();
      if (permiso == LocationPermission.denied) permiso = await Geolocator.requestPermission();
      if (permiso == LocationPermission.denied || permiso == LocationPermission.deniedForever) {
        if (!mounted) return;
        setState(() {
          _ubicacionActual = '⚠️ Permiso de ubicación denegado';
          _obteniendoUbicacion = false;
        });
        return;
      }

      final posicion = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high));
      final marcas = await placemarkFromCoordinates(posicion.latitude, posicion.longitude);
      if (!mounted) return;
      if (marcas.isNotEmpty) {
        setState(() {
          _ubicacionActual = '${marcas[0].street ?? ''}, ${marcas[0].locality ?? ''}, ${marcas[0].country ?? ''}';
          _obteniendoUbicacion = false;
          if (_origenCtrl.text.isEmpty) _origenCtrl.text = _ubicacionActual;
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _ubicacionActual = '📍 Ubicación no disponible';
        _obteniendoUbicacion = false;
      });
    }
  }

  Future<String?> _subirFoto(File foto) async {
    try {
      final request = http.MultipartRequest('POST', Uri.parse('${widget.baseUrl}/subir_foto.php'));
      request.files.add(await http.MultipartFile.fromPath('foto', foto.path));
      final response = await request.send().timeout(const Duration(seconds: 10));
      final respuesta = await response.stream.bytesToString();
      final data = json.decode(respuesta);
      return data["ruta"];
    } catch (e) {
      return null;
    }
  }

  Future<void> _iniciarRuta() async {
    final origen = _origenCtrl.text.trim();
    if (origen.isEmpty) {
      _mostrarMensaje('⚠️ Indica el origen de salida', Colors.orange);
      return;
    }

    final double? kmInicial = double.tryParse(_kmInicialCtrl.text);
    if (kmInicial == null || kmInicial <= 0) {
      _mostrarMensaje('⚠️ Ingresa un kilometraje inicial válido', Colors.orange);
      return;
    }

    if (_fotoInicio == null) {
      _mostrarMensaje('⚠️ Debes tomar una foto del odómetro', Colors.orange);
      return;
    }

    setState(() => _cargando = true);

    try {
      String? fotoUrl = await _subirFoto(_fotoInicio!);
      final bool fotoSubidaServidor = fotoUrl != null;
      fotoUrl ??= _fotoInicio!.path; 

      final rutaData = {
        'ruta_id': widget.ruta.id,
        'chofer': widget.ruta.chofer,
        'placas': _placasSeleccionadas,
        'no_economico': _noEconomicoSeleccionado,
        'origen_real': origen,
        'ubicacion_gps': _ubicacionActual,
        'km_inicial': kmInicial,
        'foto_inicio': fotoUrl,
        'fecha_inicio': DateTime.now().toIso8601String(),
        'paradas_completadas': json.encode([]),
        'sincronizado': fotoSubidaServidor ? 1 : 0,
      };

      await LocalStorageService().guardarRutaIniciada(rutaData);

      try {
        final url = Uri.parse('${widget.baseUrl}/api/iniciar_ruta_chofer.php');
        final body = {
          'ruta_id': widget.ruta.id.toString(),
          'km_inicial': kmInicial.toString(),
          'foto_inicio': fotoUrl,
          'placas': _placasSeleccionadas,
          'no_economico': _noEconomicoSeleccionado,
          'origen_real': origen,
          'ubicacion_gps': _ubicacionActual,
        };

        final response = await http.post(url, body: body).timeout(const Duration(seconds: 10));

        if (response.statusCode == 200) {
          final data = json.decode(response.body);
          if (data['success'] == true) {
            if (!mounted) return;
            _mostrarMensaje('✅ Ruta iniciada correctamente', Colors.green);
            Navigator.pop(context, true);
            return;
          }
        }
      } catch (e) {
        debugPrint('⚠️ Sin conexión directa al servidor: $e');
      }

      if (!mounted) return;
      _mostrarMensaje('⚠️ Ruta iniciada localmente (sin conexión al servidor)', Colors.orange);
      Navigator.pop(context, true);

    } catch (e) {
      debugPrint('❌ Error general: $e');
      _mostrarMensaje('⚠️ Error inesperado. Datos guardados localmente.', Colors.orange);
      if (mounted) Navigator.pop(context, true);
    } finally {
      if (mounted) setState(() => _cargando = false);
    }
  }
}
